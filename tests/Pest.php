<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Keepsuit\Spyglass\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class)->in(__DIR__);

function pruneEvents(?Application $app = null): array
{
    return array_values(array_filter(
        ($app ?? app())->make(Schedule::class)->events(),
        fn (Event $event) => str_contains($event->command ?? '', 'spyglass:prune'),
    ));
}

// Not `hasListeners()`: framework wildcard listeners make it always true.
function listens(Application $app, string $event): bool
{
    return array_key_exists($event, $app['events']->getRawListeners());
}

/**
 * Run a scenario of `Fixtures/process.php` in a PHP process of its own.
 *
 * @param  array<string, string>  $env
 */
function fixtureProcess(string $scenario, array $env = []): Process
{
    return new Process([PHP_BINARY, __DIR__.'/Fixtures/process.php', $scenario], env: $env + ['CACHE_STORE' => 'array'], timeout: 30);
}

/**
 * Run a scenario that ends its process against a fresh SQLite file, and connect to that file.
 */
function runToProcessEnd(string $scenario): Connection
{
    $file = tempnam(sys_get_temp_dir(), 'spyglass');
    register_shutdown_function(fn () => @unlink($file));

    $process = fixtureProcess($scenario, ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file]);
    $process->run();

    config()->set('database.connections.process', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']);

    return DB::connection('process');
}
