<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Keepsuit\Spyglass\Modules\Health\HealthModule;
use Keepsuit\Spyglass\Modules\Health\HealthQueries;
use Keepsuit\Spyglass\Modules\Queue\QueueModule;
use Keepsuit\Spyglass\Modules\Queue\QueueQueries;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Tests\Fixtures\WithQueueModule;

uses(WithQueueModule::class);

beforeEach(fn () => $this->enableModule($this->app, HealthModule::class));

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

/**
 * A server that accepts connections into the kernel backlog and never answers. Keep the resource referenced.
 *
 * @return array{resource, int}
 */
function stallingServer(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0');

    return [$server, (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1)];
}

/**
 * @return array<string, mixed>
 */
function stallingPgsql(int $port): array
{
    return ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $port, 'database' => 'x', 'username' => 'x', 'password' => 'x'];
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
        moduleKey('health').'.app_version' => 'v1.4.0',
        moduleKey('health').'.packages' => ['Framework' => 'laravel/framework', 'Missing' => 'keepsuit/not-installed'],
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
        ->and(DB::getConnections())->not->toHaveKey('spyglass-health')
        ->and(config('database.connections'))->not->toHaveKey('spyglass-health');
});

it('checks once two connections to the same server', function () {
    config(['database.connections.copy' => config('database.connections.'.config('database.default'))]);
    app()->instance(Spyglass::class, new Spyglass(connection: 'copy', cacheStore: 'array'));

    expect(array_keys(healthChecksOf('database')))->toBe(['database:'.config('database.default')]);
});

it('reports a storage connection that is not configured as a failed check', function () {
    config([moduleKey('health').'.packages' => null]);
    app()->instance(Spyglass::class, new Spyglass(connection: 'missing', cacheStore: 'array'));

    expect(healthChecks()['database:missing']['status'])->toBe('error');
});

it('checks the configured connections and cache stores', function () {
    config([
        'database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'cache.stores.second' => ['driver' => 'array'],
        moduleKey('health').'.connections' => ['other', 'unknown'],
        moduleKey('health').'.cache_stores' => ['second'],
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
        moduleKey('health').'.connections' => ['down'],
    ]);

    $check = healthChecks()['database:down'];

    expect($check['status'])->toBe('error')
        ->and($check['message'])->toBe('PDOException: SQLSTATE[HY000]');
})->skip(! extension_loaded('pdo_mysql'), 'pdo_mysql is not installed.');

it('gives up on a database server that does not answer after the timeout', function (string $driver) {
    [$server, $port] = stallingServer();
    config([
        'database.connections.stalled' => ['driver' => $driver, 'host' => '127.0.0.1', 'port' => $port, 'database' => 'x', 'username' => 'x', 'password' => 'x'],
        moduleKey('health').'.connections' => ['stalled'],
    ]);

    $start = microtime(true);
    $check = healthChecks()['database:stalled'];

    // MySQL: Laravel retries the connect once as a lost connection.
    expect($check['status'])->toBe('error')
        ->and(microtime(true) - $start)->toBeGreaterThan(1.5)->toBeLessThan($driver === 'mysql' ? 5 : 3);
})->with([
    'pgsql' => fn () => extension_loaded('pdo_pgsql') ? 'pgsql' : test()->markTestSkipped('pdo_pgsql is not installed.'),
    'mysql' => fn () => extension_loaded('pdo_mysql') ? 'mysql' : test()->markTestSkipped('pdo_mysql is not installed.'),
]);

it('gives up on a redis cache that does not answer after the timeout', function () {
    [$server, $port] = stallingServer();
    config([
        'database.redis.stalled' => ['host' => '127.0.0.1', 'port' => $port],
        'database.redis.client' => 'phpredis',
        'cache.stores.stalled' => ['driver' => 'redis', 'connection' => 'stalled'],
        moduleKey('health').'.cache_stores' => ['stalled'],
    ]);

    $start = microtime(true);
    $check = healthChecks()['cache:stalled'];

    expect($check['status'])->toBe('error')
        ->and(microtime(true) - $start)->toBeGreaterThan(1.5)->toBeLessThan(3);
})->skip(! extension_loaded('redis'), 'phpredis is not installed.');

it('skips the database cache and queue once a database check failed', function () {
    config([
        'database.connections.down' => ['driver' => 'sqlite', 'database' => '/nonexistent/spyglass.sqlite', 'prefix' => ''],
        'cache.stores.db' => ['driver' => 'database', 'table' => 'cache'],
        moduleKey('health').'.connections' => ['down'],
        moduleKey('health').'.cache_stores' => ['db', 'array'],
    ]);

    $checks = healthChecks();

    expect($checks['database:down']['status'])->toBe('error')
        ->and($checks['cache:db'])->toMatchArray(['status' => 'warning', 'message' => 'Skipped: a database check failed.'])
        ->and($checks['cache:array']['status'])->toBe('ok')
        ->and($checks['queue:database:default'])->toMatchArray(['status' => 'warning', 'message' => 'Skipped: a database check failed.']);
});

it('checks the default cache store with a real round trip, without leaving the key behind', function () {
    $checks = healthChecksOf('cache');

    expect($checks)->toHaveCount(1)
        ->and($checks['cache:array']['status'])->toBe('ok')
        ->and(cache()->store('array')->getStore())->toEqual(new ArrayStore);
});

it('reports a cache error without the query bindings', function () {
    config([
        'cache.stores.broken' => ['driver' => 'database', 'table' => 'missing_cache', 'connection' => config('database.default')],
        moduleKey('health').'.cache_stores' => ['broken'],
    ]);

    $check = healthChecks()['cache:broken'];

    expect($check['status'])->toBe('error')
        ->and($check['message'])->toStartWith('Illuminate\Database\QueryException: SQLSTATE[')
        ->and($check['message'])->not->toContain('spyglass:health:');
});

it('reports the pending jobs of the default queue without the queue module', function () {
    config([moduleKey('queue').'.enabled' => false]);
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

it('does not count a queue or a cache that stores nothing, and reports a backend that fails', function () {
    config(['queue.default' => 'sync', 'cache.stores.none' => ['driver' => 'null'], moduleKey('health').'.cache_stores' => ['none']]);

    expect(healthChecks()['queue:sync:default'])->toMatchArray(['status' => 'info', 'value' => null])
        ->and(healthChecks()['cache:none'])->toMatchArray(['status' => 'info', 'value' => null]);

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
    [$server, $port] = stallingServer();
    config(['database.connections.stalled' => stallingPgsql($port)]);
    app()->instance(Spyglass::class, new Spyglass(connection: 'stalled', cacheStore: 'array'));

    $start = microtime(true);
    $checks = healthChecks();

    expect($checks['database:stalled']['status'])->toBe('error')
        ->and($checks)->toHaveKey('queue:database:default')
        ->and(microtime(true) - $start)->toBeLessThan(3);
})->skip(! extension_loaded('pdo_pgsql'), 'pdo_pgsql is not installed.');

it('does not read the queue list from a storage left out of the checked connections', function () {
    [$server, $port] = stallingServer();
    config([
        'database.connections.stalled' => stallingPgsql($port),
        moduleKey('health').'.connections' => [config('database.default')],
    ]);
    app()->instance(Spyglass::class, new Spyglass(connection: 'stalled', cacheStore: 'array'));

    $start = microtime(true);
    $checks = healthChecks();

    expect($checks)->toHaveKey('queue:database:default')
        ->and(microtime(true) - $start)->toBeLessThan(1);
})->skip(! extension_loaded('pdo_pgsql'), 'pdo_pgsql is not installed.');

it('restores the socket timeouts', function () {
    $previous = [ini_set('default_socket_timeout', '45'), ini_set('mysqlnd.net_read_timeout', '46')];

    app(HealthQueries::class)->checks();

    expect(ini_get('default_socket_timeout'))->toBe('45')
        ->and(ini_get('mysqlnd.net_read_timeout'))->toBe('46');

    ini_set('default_socket_timeout', (string) $previous[0]);
    ini_set('mysqlnd.net_read_timeout', (string) $previous[1]);
});
