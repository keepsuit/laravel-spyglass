<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Keepsuit\Spyglass\Modules\Health\HealthQueries;
use Keepsuit\Spyglass\Modules\Queue\QueueModule;
use Keepsuit\Spyglass\Modules\Queue\QueueQueries;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Tests\Fixtures\WithQueueModule;

uses(WithQueueModule::class);

/**
 * @return array<string, array{key: string, label: string, status: string, value: mixed, message: ?string}>
 */
function healthChecks(?HealthQueries $health = null): array
{
    return collect(($health ?? app(HealthQueries::class))->checks())->keyBy('key')->all();
}

/**
 * @return array<string, array{key: string, label: string, status: string, value: mixed, message: ?string}>
 */
function healthChecksOf(string $prefix): array
{
    return array_filter(healthChecks(), fn (array $check) => str_starts_with($check['key'], $prefix.':'));
}

it('reports the environment, debug mode and versions first', function () {
    config(['app.debug' => false]);

    $checks = app(HealthQueries::class)->checks();

    expect(array_column(array_slice($checks, 0, 5), 'key'))->toBe(['environment', 'debug', 'php', 'laravel', 'package:keepsuit/laravel-spyglass'])
        ->and($checks[0])->toMatchArray(['status' => 'info', 'value' => 'testing'])
        ->and($checks[1])->toMatchArray(['status' => 'ok', 'value' => false])
        ->and($checks[2]['value'])->toBe(PHP_VERSION)
        ->and($checks[3]['value'])->toBe(app()->version())
        ->and($checks[4]['label'])->toBe('Spyglass');
});

it('flags debug mode only in production', function (string $environment, string $status) {
    config(['app.debug' => true]);
    app()['env'] = $environment;
    $check = healthChecks()['debug'];
    // Rolling back migrations asks for confirmation in production.
    app()['env'] = 'testing';

    expect($check)->toMatchArray(['status' => $status, 'value' => true]);
})->with([
    ['production', 'error'],
    ['staging', 'info'],
]);

it('shows the application version and installed packages only when known', function () {
    expect(healthChecks())->not->toHaveKey('version');

    config([
        'spyglass.modules.health.version' => 'v1.4.0',
        'spyglass.modules.health.versions' => ['Framework' => 'laravel/framework', 'Missing' => 'keepsuit/not-installed'],
    ]);

    $checks = healthChecks();

    expect($checks['version'])->toMatchArray(['label' => 'Application version', 'status' => 'info', 'value' => 'v1.4.0'])
        ->and($checks['package:laravel/framework']['label'])->toBe('Framework')
        ->and($checks)->not->toHaveKey('package:keepsuit/not-installed')
        ->and($checks)->not->toHaveKey('package:keepsuit/laravel-spyglass');
});

it('checks the default database once when Spyglass stores to a copy of it', function () {
    $checks = healthChecksOf('database');
    $check = $checks['database:'.config('database.default')];

    expect($checks)->toHaveCount(1)
        ->and($check)->toMatchArray(['status' => 'ok', 'message' => null])
        ->and($check['value'])->toBeFloat()
        ->and(DB::getConnections())->not->toHaveKey('spyglass-health');
});

it('checks the configured connections and cache stores', function () {
    config([
        'database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'cache.stores.second' => ['driver' => 'array'],
        'spyglass.modules.health.connections' => ['other', 'unknown'],
        'spyglass.modules.health.cache_stores' => ['second'],
    ]);

    $checks = healthChecks();

    expect(array_keys(healthChecksOf('database')))->toBe(['database:other', 'database:unknown'])
        ->and($checks['database:other']['status'])->toBe('ok')
        ->and($checks['database:unknown'])->toMatchArray(['status' => 'error', 'message' => 'InvalidArgumentException: Database connection [unknown] is not configured.'])
        ->and(array_keys(healthChecksOf('cache')))->toBe(['cache:second'])
        ->and($checks['cache:second']['status'])->toBe('ok');
});

it('reports a database error with its SQLSTATE only', function () {
    config([
        'database.connections.down' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'secret-user', 'password' => 'x'],
        'spyglass.modules.health.connections' => ['down'],
    ]);

    $check = healthChecks()['database:down'];

    expect($check['status'])->toBe('error')
        ->and($check['message'])->toStartWith('PDOException: SQLSTATE[')
        ->and($check['message'])->not->toContain('refused')
        ->and($check['message'])->not->toContain('secret-user');
})->skip(! extension_loaded('pdo_mysql'), 'pdo_mysql is not installed.');

it('gives up on an unreachable database after the connect timeout', function () {
    config([
        'database.connections.unreachable' => ['driver' => 'pgsql', 'host' => '10.255.255.1', 'port' => 5432, 'database' => 'x', 'username' => 'x', 'password' => 'x'],
        'spyglass.modules.health.connections' => ['unreachable'],
    ]);

    $start = microtime(true);
    $check = healthChecks()['database:unreachable'];

    expect($check['status'])->toBe('error')
        ->and(microtime(true) - $start)->toBeLessThan(4);
})->skip(! extension_loaded('pdo_pgsql'), 'pdo_pgsql is not installed.');

it('gives up on an unreachable redis cache after the socket timeout', function () {
    config([
        'database.redis.unreachable' => ['host' => '10.255.255.1', 'port' => 6379],
        'database.redis.client' => 'phpredis',
        'cache.stores.unreachable' => ['driver' => 'redis', 'connection' => 'unreachable'],
        'spyglass.modules.health.cache_stores' => ['unreachable'],
    ]);

    $start = microtime(true);
    $check = healthChecks()['cache:unreachable'];

    expect($check['status'])->toBe('error')
        ->and(microtime(true) - $start)->toBeLessThan(4);
})->skip(! extension_loaded('redis'), 'phpredis is not installed.');

it('checks the default cache store with a real round trip, without leaving the key behind', function () {
    $checks = healthChecksOf('cache');

    expect($checks)->toHaveCount(1)
        ->and($checks['cache:array']['status'])->toBe('ok')
        ->and(cache()->store('array')->getStore())->toEqual(new ArrayStore);
});

it('reports a cache error without the query bindings', function () {
    config([
        'cache.stores.broken' => ['driver' => 'database', 'table' => 'missing_cache', 'connection' => config('database.default')],
        'spyglass.modules.health.cache_stores' => ['broken'],
    ]);

    $check = healthChecks()['cache:broken'];

    expect($check['status'])->toBe('error')
        ->and($check['message'])->toStartWith('Illuminate\Database\QueryException: SQLSTATE[')
        ->and($check['message'])->not->toContain('spyglass:health:');
});

it('reports the pending jobs of the default queue without the queue module', function () {
    config(['spyglass.modules.queue.enabled' => false]);
    Queue::connection('database')->pushRaw('{}');

    expect(healthChecksOf('queue'))->toBe([
        'queue:database:default' => ['key' => 'queue:database:default', 'label' => 'Queue database / default', 'status' => 'ok', 'value' => 1, 'message' => null],
    ]);
});

it('reports the queues seen by the queue module', function () {
    DB::table(QueueModule::AGGREGATES)->insert([
        ['bucket' => now('UTC')->format('Y-m-d H:i:s'), 'connection' => 'database', 'queue' => 'emails', 'job' => 'A'],
        ['bucket' => now('UTC')->subDays(2)->format('Y-m-d H:i:s'), 'connection' => 'database', 'queue' => 'old', 'job' => 'A'],
    ]);
    Queue::connection('database')->pushRaw('{}', 'emails');

    expect(array_keys(healthChecksOf('queue')))->toBe(['queue:database:emails'])
        ->and(healthChecks()['queue:database:emails']['value'])->toBe(1);
});

it('does not count a queue for the sync driver and reports a backend that fails', function () {
    config(['queue.default' => 'sync']);

    expect(healthChecks()['queue:sync:default'])->toMatchArray(['status' => 'info', 'value' => null]);

    config([
        'queue.connections.broken' => ['driver' => 'database', 'table' => 'missing_jobs', 'queue' => 'default'],
        'queue.default' => 'broken',
    ]);

    expect(healthChecks()['queue:broken:default'])->toMatchArray(['status' => 'error'])
        ->and(healthChecks()['queue:broken:default']['message'])->toStartWith('Illuminate\Database\QueryException: SQLSTATE[');
});

it('skips the checks left when the time budget is exhausted, never the environment', function () {
    $health = new class(app(Spyglass::class), app(QueueQueries::class)) extends HealthQueries
    {
        protected const int BUDGET = 0;
    };

    $checks = healthChecks($health);

    expect($checks['php']['status'])->toBe('info')
        ->and($checks['database:'.config('database.default')])->toMatchArray(['status' => 'warning', 'message' => 'Skipped: time budget exhausted.'])
        ->and($checks['cache:array']['status'])->toBe('warning')
        ->and($checks['queue:database:default']['status'])->toBe('warning');
});

it('does not read the queue list from a storage that does not answer', function () {
    config(['database.connections.unreachable' => ['driver' => 'pgsql', 'host' => '10.255.255.1', 'port' => 5432, 'database' => 'x', 'username' => 'x', 'password' => 'x']]);
    app()->instance(Spyglass::class, new Spyglass(connection: 'unreachable', cacheStore: 'array'));

    $start = microtime(true);
    $checks = healthChecks();

    expect($checks['database:unreachable']['status'])->toBe('error')
        ->and($checks)->toHaveKey('queue:database:default')
        ->and(microtime(true) - $start)->toBeLessThan(4);
})->skip(! extension_loaded('pdo_pgsql'), 'pdo_pgsql is not installed.');

it('restores the socket timeout', function () {
    ini_set('default_socket_timeout', '45');

    app(HealthQueries::class)->checks();

    expect(ini_get('default_socket_timeout'))->toBe('45');
});
