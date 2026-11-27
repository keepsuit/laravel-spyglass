<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Keepsuit\Spyglass\Support\Sources;
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

/**
 * Exception group totals, with the columns `ExceptionQueries` returns.
 */
function exceptionGroupsTable(?Connection $db = null): Builder
{
    return ($db ?? DB::connection())->table(Sources::GROUPS)
        ->select(['id', 'signature as fingerprint', 'class', 'message', 'file', 'line', 'count', 'first_seen_at', 'last_seen_at', 'resolved_at'])
        ->where('kind', 'exception')
        ->where('source_type', '');
}

function exceptionOccurrencesTable(?Connection $db = null): Builder
{
    return ($db ?? DB::connection())->table(Sources::ENTRIES)
        ->select([
            'id', 'signature as fingerprint', 'recorded_at as occurred_at', 'message', 'body as trace', 'cause_class', 'cause_message',
            'source_type', 'source', 'source_id', 'route', 'user_id', 'scope', 'context',
        ])
        ->where('kind', 'exception');
}

function logEntriesTable(): Builder
{
    return DB::table(Sources::ENTRIES)
        ->select(['id', 'level', 'message', 'body as log_context', 'recorded_at as logged_at', 'source_type', 'source', 'source_id', 'scope', 'context'])
        ->where('kind', 'log');
}

/**
 * Source rows of exception groups and log messages, without the totals.
 */
function sourcesTable(): Builder
{
    return DB::table(Sources::GROUPS)
        ->select(['id', 'kind', 'signature', 'source_type', 'source_hash', 'source', 'count', 'last_seen_at'])
        ->where('source_type', '!=', '');
}

/**
 * An exception group total row, with an occurrence per scope.
 */
function seedExceptionGroup(string $fingerprint, array $group = [], array $scopes = []): void
{
    DB::table(Sources::GROUPS)->insert([
        'kind' => 'exception',
        'signature' => $fingerprint,
        'class' => RuntimeException::class,
        'message' => 'Boom',
        'file' => 'app/Foo.php',
        'line' => 1,
        'count' => 1,
        'first_seen_at' => '2026-03-01 10:00:00',
        'last_seen_at' => '2026-03-01 10:00:00',
        ...$group,
    ]);

    foreach ($scopes as $scope) {
        DB::table(Sources::ENTRIES)->insert([
            'kind' => 'exception',
            'signature' => $fingerprint,
            'recorded_at' => '2026-03-01 10:00:00',
            'message' => 'Boom',
            'body' => '[]',
            'source_type' => 'command',
            'scope' => $scope,
        ]);
    }
}
