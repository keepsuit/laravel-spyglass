<?php

namespace Keepsuit\Spyglass\Modules\Health;

use Closure;
use Composer\InstalledVersions;
use Generator;
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

            // phpredis and mysqlnd fall back to it when no connect timeout is configured.
            $socketTimeout = ini_set('default_socket_timeout', (string) static::TIMEOUT);

            // The queue list comes from the storage, read without a connect timeout of its own.
            $storageAnswers = function () use (&$checks, $deadline) {
                return hrtime(true) <= $deadline
                    && ! collect($checks)->contains(fn (array $check) => str_starts_with($check['key'], 'database:') && $check['status'] === 'error');
            };

            try {
                foreach ($this->probes($storageAnswers) as $key => [$label, $probe]) {
                    $checks[] = hrtime(true) > $deadline
                        ? $this->check($key, $label, 'warning', message: 'Skipped: time budget exhausted.')
                        : $this->run($key, $label, $probe);
                }
            } finally {
                if ($socketTimeout !== false) {
                    ini_set('default_socket_timeout', $socketTimeout);
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

        foreach (config('spyglass.modules.health.versions', []) as $label => $package) {
            if (InstalledVersions::isInstalled($package)) {
                $checks[] = $this->check("package:{$package}", (string) $label, 'info', InstalledVersions::getPrettyVersion($package));
            }
        }

        return $checks;
    }

    /**
     * @param  Closure(): bool  $storageAnswers
     * @return Generator<string, array{string, Closure(): array{0: Status, 1: mixed, 2?: string}}>
     */
    protected function probes(Closure $storageAnswers): Generator
    {
        foreach ($this->connections() as $name) {
            yield "database:{$name}" => ["Database {$name}", fn () => $this->database($name)];
        }

        foreach ($this->cacheStores() as $name) {
            yield "cache:{$name}" => ["Cache {$name}", fn () => $this->cache($name)];
        }

        foreach ($this->queueNames($storageAnswers()) as [$connection, $queue]) {
            yield "queue:{$connection}:{$queue}" => ["Queue {$connection} / {$queue}", fn () => $this->queue($connection, $queue)];
        }
    }

    /**
     * @return list<string>
     */
    protected function connections(): array
    {
        $names = config('spyglass.modules.health.connections')
            ?? [config('database.default'), $this->spyglass->connection()->getName()];

        // The storage connection is a copy of an application connection: the same server is checked once.
        $unique = [];

        foreach ($names as $name) {
            $unique[json_encode(config("database.connections.{$name}") ?? $name)] ??= $name;
        }

        return array_values($unique);
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

        // A connection of its own gets the connect timeout; pdo_pgsql ignores the socket timeout.
        config(['database.connections.'.static::CONNECTION => array_replace($config, [
            'options' => array_replace($config['options'] ?? [], [PDO::ATTR_TIMEOUT => static::TIMEOUT]),
        ])]);

        try {
            $start = hrtime(true);
            // Plain PDO: Laravel would retry a connect that timed out as a lost connection, doubling the wait.
            DB::connection(static::CONNECTION)->getPdo()->query('select 1');

            return ['ok', $this->elapsed($start)];
        } finally {
            DB::purge(static::CONNECTION);
        }
    }

    /**
     * @return array{0: Status, 1: float, 2?: string}
     */
    protected function cache(string $name): array
    {
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
        $driver = config("queue.connections.{$connection}.driver");

        if (in_array($driver, ['sync', 'null'], true)) {
            return ['info', null, "The {$driver} driver keeps no queue."];
        }

        return ['ok', Queue::connection($connection)->pendingSize($queue)];
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
