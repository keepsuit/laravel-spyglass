<?php

namespace Keepsuit\Spyglass\Modules\Health;

use Closure;
use Composer\InstalledVersions;
use Generator;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;
use Keepsuit\Spyglass\Modules\Queue\QueueQueries;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Support\Text;
use PDO;
use Throwable;

/**
 * Checks of the application's services, run on demand: nothing is stored or cached.
 *
 * @phpstan-type Status 'ok'|'warning'|'error'|'info'
 * @phpstan-type Check array{key: string, label: string, status: Status, value: mixed, message: ?string}
 */
class HealthQueries
{
    /** Connect timeout, in seconds, of the services the checks connect to. */
    protected const int TIMEOUT = 2;

    /** Seconds after which the remaining checks are skipped, so several unreachable services still give an answer. */
    protected const int BUDGET = 8;

    protected const string CONNECTION = 'spyglass-health';

    public function __construct(protected Spyglass $spyglass, protected QueueQueries $queues) {}

    /**
     * Environment and versions first, then databases, caches and queues. Database and cache values are
     * latencies in milliseconds, queue values pending jobs.
     *
     * @return list<Check>
     */
    public function checks(): array
    {
        return $this->spyglass->ignore(function () {
            $checks = $this->info();
            $deadline = hrtime(true) + static::BUDGET * 1_000_000_000;
            $databaseFailed = false;
            $storageAnswers = false;

            // Connect and read timeouts of phpredis and mysqlnd connections opened from here on.
            $ini = [];
            foreach (['default_socket_timeout', 'mysqlnd.net_read_timeout'] as $option) {
                $ini[$option] = ini_set($option, (string) static::TIMEOUT);
            }

            try {
                foreach ($this->probes($storageAnswers) as $key => [$label, $probe, $usesDatabase]) {
                    $check = match (true) {
                        hrtime(true) > $deadline => $this->check($key, $label, 'warning', message: 'Skipped: time budget exhausted.'),
                        // It would reach the database through a connection without the timeouts above.
                        $usesDatabase && $databaseFailed => $this->check($key, $label, 'warning', message: 'Skipped: a database check failed.'),
                        default => $this->run($key, $label, $probe),
                    };

                    if (str_starts_with($key, 'database:')) {
                        $databaseFailed = $databaseFailed || $check['status'] === 'error';
                        $storageAnswers = $storageAnswers || ($check['status'] === 'ok' && $this->isStorage(substr($key, 9)));
                    }

                    $storageAnswers = $storageAnswers && ! $databaseFailed && hrtime(true) <= $deadline;
                    $checks[] = $check;
                }
            } finally {
                foreach (array_filter($ini, fn ($value) => $value !== false) as $option => $value) {
                    ini_set($option, $value);
                }
            }

            return $checks;
        });
    }

    /**
     * @return list<Check>
     */
    protected function info(): array
    {
        $debug = (bool) config('app.debug');
        $exposed = $debug && app()->isProduction();

        $checks = [
            $this->check('environment', 'Environment', 'info', app()->environment()),
            $this->check('debug', 'Debug mode', $exposed ? 'error' : ($debug ? 'info' : 'ok'), $debug, $exposed ? 'APP_DEBUG is enabled in production.' : null),
            $this->check('php', 'PHP', 'info', PHP_VERSION),
            $this->check('laravel', 'Laravel', 'info', app()->version()),
        ];

        if (filled($version = config('spyglass.modules.health.version'))) {
            $checks[] = $this->check('version', 'Application version', 'info', (string) $version);
        }

        foreach (config('spyglass.modules.health.versions') ?? [] as $label => $package) {
            if (InstalledVersions::isInstalled($package)) {
                $checks[] = $this->check("package:{$package}", (string) $label, 'info', InstalledVersions::getPrettyVersion($package));
            }
        }

        return $checks;
    }

    /**
     * Each probe with whether it uses an application database connection. The queue list is read from the
     * storage only once `$storageAnswers` is set, by the time the queue probes start.
     *
     * @return Generator<string, array{string, Closure(): array{0: Status, 1: mixed, 2?: string}, bool}>
     */
    protected function probes(bool &$storageAnswers): Generator
    {
        foreach ($this->connections() as $name) {
            yield "database:{$name}" => ["Database {$name}", fn () => $this->database($name), false];
        }

        foreach ($this->cacheStores() as $name) {
            yield "cache:{$name}" => ["Cache {$name}", fn () => $this->cache($name), config("cache.stores.{$name}.driver") === 'database'];
        }

        foreach ($this->queueNames($storageAnswers) as [$connection, $queue]) {
            yield "queue:{$connection}:{$queue}" => ["Queue {$connection} / {$queue}", fn () => $this->queue($connection, $queue), config("queue.connections.{$connection}.driver") === 'database'];
        }
    }

    /**
     * @return list<string>
     */
    protected function connections(): array
    {
        $names = config('spyglass.modules.health.connections')
            ?? [config('database.default'), $this->spyglass->connectionName()];

        // The storage connection is a copy of an application connection: the same server is checked once.
        $unique = [];

        foreach ($names as $name) {
            $unique[$this->server($name)] ??= $name;
        }

        return array_values($unique);
    }

    protected function isStorage(string $connection): bool
    {
        return $this->server($connection) === $this->server($this->spyglass->connectionName());
    }

    protected function server(string $connection): string
    {
        return (string) json_encode(config("database.connections.{$connection}") ?? $connection);
    }

    /**
     * @return list<string>
     */
    protected function cacheStores(): array
    {
        $names = config('spyglass.modules.health.cache_stores')
            ?? [config('cache.default'), $this->spyglass->cacheStore()];

        return array_values(array_unique($names));
    }

    /**
     * Queues seen in the last day, or the default queue.
     *
     * @return list<array{string, string}>
     */
    protected function queueNames(bool $fromStorage): array
    {
        $queues = [];

        if ($fromStorage && isset($this->spyglass->modules()['queue'])) {
            try {
                foreach ($this->queues->queues(now()->subDay()) as $queue) {
                    $queues[] = [$queue['connection'], $queue['queue']];
                }
            } catch (Throwable) {
                // The database check already reports a storage that does not answer.
            }
        }

        if ($queues === []) {
            $connection = (string) config('queue.default');
            $queues[] = [$connection, (string) (config("queue.connections.{$connection}.queue") ?? 'default')];
        }

        return $queues;
    }

    /**
     * @return array{Status, float}
     */
    protected function database(string $name): array
    {
        $config = config("database.connections.{$name}") ?? throw new InvalidArgumentException("Database connection [{$name}] is not configured.");

        try {
            $start = hrtime(true);
            // A connection of its own gets the connect timeout, which pdo_pgsql needs: it ignores the socket timeout.
            // ponytail: Laravel retries a connect that fails as a lost connection, so MySQL can take twice the timeout.
            DB::connectUsing(static::CONNECTION, array_replace($config, [
                'options' => array_replace($config['options'] ?? [], [PDO::ATTR_TIMEOUT => static::TIMEOUT]),
            ]));
            DB::connection(static::CONNECTION)->getPdo()->query('select 1');

            return ['ok', $this->elapsed($start)];
        } finally {
            DB::purge(static::CONNECTION);
        }
    }

    /**
     * @return array{0: Status, 1: ?float, 2?: string}
     */
    protected function cache(string $name): array
    {
        if (config("cache.stores.{$name}.driver") === 'null') {
            return ['info', null, 'The null driver stores nothing.'];
        }

        $cache = Cache::store($name);
        $key = 'spyglass:health:'.Str::random(16);

        $start = hrtime(true);
        $cache->put($key, $key, 10);
        $read = $cache->get($key);
        $elapsed = $this->elapsed($start);
        $cache->forget($key);

        return $read === $key ? ['ok', $elapsed] : ['error', $elapsed, 'The value written could not be read back.'];
    }

    /**
     * @return array{0: Status, 1: ?int, 2?: string}
     */
    protected function queue(string $connection, string $queue): array
    {
        $backend = Queue::connection($connection);

        // Also the deferred and background drivers, which extend the sync one.
        if ($backend instanceof SyncQueue || $backend instanceof NullQueue) {
            return ['info', null, 'Jobs on this connection are not queued.'];
        }

        return ['ok', $backend->pendingSize($queue)];
    }

    /**
     * @param  Closure(): array{0: Status, 1: mixed, 2?: string}  $probe
     * @return Check
     */
    protected function run(string $key, string $label, Closure $probe): array
    {
        try {
            $result = $probe();

            return $this->check($key, $label, $result[0], $result[1], $result[2] ?? null);
        } catch (Throwable $e) {
            return $this->check($key, $label, 'error', message: Text::clip($e::class.': '.ExceptionsModule::safeMessage($e), 1024));
        }
    }

    /**
     * @param  Status  $status
     * @return Check
     */
    protected function check(string $key, string $label, string $status, mixed $value = null, ?string $message = null): array
    {
        return compact('key', 'label', 'status', 'value', 'message');
    }

    protected function elapsed(int $start): float
    {
        return round((hrtime(true) - $start) / 1_000_000, 1);
    }
}
