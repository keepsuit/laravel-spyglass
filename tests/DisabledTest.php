<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Tests\Fixtures\TestModule;

it('registers nothing when disabled', function () {
    TestModule::$booted = false;

    $app = $this->bootApplication(['spyglass.enabled' => false]);

    expect(listens($app, 'Laravel\Octane\Events\RequestReceived'))->toBeFalse()
        ->and(TestModule::$booted)->toBeFalse()
        ->and($app['router']->has('spyglass.index'))->toBeFalse()
        ->and($app[Gate::class]->has('viewSpyglass'))->toBeFalse()
        ->and(pruneEvents($app))->toBeEmpty();

    $spyglass = $app->make(Spyglass::class);
    $spyglass->insert('spyglass_test_entries', ['name' => 'a']);
    $spyglass->upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'a'], increment: ['count' => 1]);

    expect(fn () => $spyglass->flush())->not->toThrow(Throwable::class)
        ->and(DB::table('spyglass_test_entries')->count())->toBe(0);
});

it('registers listeners, routes, gate and schedule when enabled', function () {
    TestModule::$booted = false;

    $app = $this->bootApplication([]);

    expect(listens($app, 'Laravel\Octane\Events\RequestReceived'))->toBeTrue()
        ->and(TestModule::$booted)->toBeTrue()
        ->and($app['router']->has('spyglass.index'))->toBeTrue()
        ->and($app[Gate::class]->has('viewSpyglass'))->toBeTrue()
        ->and(pruneEvents($app))->toHaveCount(1);
});

it('refuses two modules with the same name', function () {
    config()->set('spyglass.modules', [
        TestModule::class => ['enabled' => true],
        SameNameModule::class => ['enabled' => true],
    ]);

    expect(fn () => app(Spyglass::class)->modules())->toThrow(InvalidArgumentException::class, 'share the name [test]');
});

it('declares the spyglass log channel unless the application does', function () {
    expect(config('logging.channels.spyglass'))->toBe(['driver' => 'spyglass'])
        ->and($this->bootApplication(['logging.channels.spyglass' => ['driver' => 'spyglass', 'level' => 'error']])['config']->get('logging.channels.spyglass'))
        ->toBe(['driver' => 'spyglass', 'level' => 'error']);
});

class SameNameModule extends TestModule {}
