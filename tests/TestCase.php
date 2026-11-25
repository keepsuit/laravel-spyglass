<?php

namespace Keepsuit\Spyglass\Tests;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\SpyglassServiceProvider;
use Keepsuit\Spyglass\Tests\Fixtures\TestModule;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * No `RefreshDatabase`: flushes must run at transaction level 0 as in production. Testbench migrates
 * before each test and rolls back after it.
 */
class TestCase extends Orchestra
{
    use WithLaravelMigrations;

    /** @var list<MessageLogged> */
    public array $spyglassErrors = [];

    /** @var array<string, mixed> */
    protected array $bootConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Storage errors are swallowed, so an unexpected one would otherwise go unnoticed.
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            if (str_starts_with($event->message, 'Spyglass')) {
                $this->spyglassErrors[] = $event;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->assertSame([], array_map(fn (MessageLogged $e) => $e->message, $this->spyglassErrors), 'Unexpected Spyglass errors were logged.');

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            SpyglassServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        Spyglass::$modules = ['test' => TestModule::class];

        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => env('DB_CONNECTION', 'testing'),
            'database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array',
            'queue.default' => 'database',
            'queue.failed.driver' => 'null',
            'spyglass.modules.test' => ['enabled' => true, 'retention' => ['entries' => 7]],
            ...$this->bootConfig,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    /**
     * Build a separate application with config that must be in place before the provider boots.
     *
     * @param  array<string, mixed>  $config
     */
    public function bootApplication(array $config): Application
    {
        $this->bootConfig = $config;

        try {
            return $this->createApplication();
        } finally {
            $this->bootConfig = [];
            Container::setInstance($this->app);
            Facade::setFacadeApplication($this->app);
            Facade::clearResolvedInstances();
        }
    }
}
