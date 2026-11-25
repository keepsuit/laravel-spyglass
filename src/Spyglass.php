<?php

namespace Keepsuit\Spyglass;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Keepsuit\Spyglass\Modules\Module;
use RuntimeException;
use Throwable;

class Spyglass
{
    /**
     * Module classes keyed by their `spyglass.modules` config key.
     *
     * @internal
     *
     * @var array<string, class-string<Module>>
     */
    public static array $modules = [];

    /** @var array<string, list<array<string, mixed>>> */
    protected array $inserts = [];

    /** @var array<string, array<string, array{key: non-empty-array<non-empty-string, mixed>, values: array<string, mixed>, increment: array<string, int|float>, max: array<string, mixed>}>> */
    protected array $upserts = [];

    protected bool $ignoring = false;

    /** @var (Closure(Request): bool)|null */
    protected ?Closure $authUsing = null;

    /** @var (Closure(array<string, mixed>): ?string)|null */
    protected ?Closure $resolveScopeUsing = null;

    /** @var (Closure(list<string>): array<string, string>)|null */
    protected ?Closure $resolveScopeLabelsUsing = null;

    public function __construct(
        protected string $connection,
        protected string $cacheStore,
        protected bool $enabled = true,
    ) {}

    public function connection(): Connection
    {
        return DB::connection($this->connection);
    }

    public function cache(): CacheRepository
    {
        return Cache::store($this->cacheStore);
    }

    /**
     * @param  Closure(Request): bool  $callback
     */
    public function auth(Closure $callback): static
    {
        $this->authUsing = $callback;

        return $this;
    }

    public function check(Request $request): bool
    {
        return $this->authUsing
            ? (bool) ($this->authUsing)($request)
            : Gate::check('viewSpyglass', [$request->user()]);
    }

    /**
     * @param  (Closure(array<string, mixed>): ?string)|null  $callback
     */
    public function resolveScopeUsing(?Closure $callback): static
    {
        $this->resolveScopeUsing = $callback;

        return $this;
    }

    /**
     * @param  (Closure(list<string>): array<string, string>)|null  $callback
     */
    public function resolveScopeLabelsUsing(?Closure $callback): static
    {
        $this->resolveScopeLabelsUsing = $callback;

        return $this;
    }

    /**
     * @param  list<string>  $scopes
     * @return array<string, string> scope => label
     */
    public function scopeLabels(array $scopes): array
    {
        $labels = $this->resolveScopeLabelsUsing ? ($this->resolveScopeLabelsUsing)($scopes) : [];

        return array_map(fn (string $scope) => $labels[$scope] ?? $scope, array_combine($scopes, $scopes));
    }

    /**
     * Snapshot of the whitelisted Context and the scope derived from it, as `scope` and `context` columns.
     *
     * @return array{scope: ?string, context: ?string}
     */
    public function captureContext(): array
    {
        $keys = config('spyglass.context.keys', []);
        $context = $keys === [] ? [] : Context::only($keys);

        return [
            'scope' => $this->resolveScopeUsing ? ($this->resolveScopeUsing)($context) : null,
            'context' => $context === [] ? null : (json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: null),
        ];
    }

    /**
     * Buffer a row, adding `scope` and `context` captured now unless the row already has them.
     *
     * @param  array<string, mixed>  $row
     */
    public function insert(string $table, array $row): void
    {
        if (! $this->enabled || $this->ignoring) {
            return;
        }

        if (! array_key_exists('scope', $row)) {
            $row += $this->captureContext();
        }

        $this->inserts[$table][] = $row;
    }

    /**
     * Buffer an upsert on the unique `$key` columns. Calls with the same key are merged in memory
     * (last `$values` win, `$increment` is summed, `$max` keeps the greatest) and stored in one
     * batch per table. No context is captured: merge `captureContext()` into `$values` if needed.
     *
     * @param  non-empty-array<non-empty-string, mixed>  $key
     * @param  array<string, mixed>  $values
     * @param  array<string, int|float>  $increment
     * @param  array<string, mixed>  $max
     */
    public function upsert(string $table, array $key, array $values = [], array $increment = [], array $max = []): void
    {
        if (! $this->enabled || $this->ignoring) {
            return;
        }

        ksort($key);
        $id = serialize($key);

        if (! isset($this->upserts[$table][$id])) {
            $this->upserts[$table][$id] = ['key' => $key, 'values' => $values, 'increment' => $increment, 'max' => $max];

            return;
        }

        $pending = &$this->upserts[$table][$id];
        $pending['values'] = $values + $pending['values'];

        foreach ($increment as $column => $amount) {
            $pending['increment'][$column] = ($pending['increment'][$column] ?? 0) + $amount;
        }

        foreach ($max as $column => $value) {
            $pending['max'][$column] = isset($pending['max'][$column]) ? max($pending['max'][$column], $value) : $value;
        }
    }

    /**
     * Write and clear the buffer. Storage errors are logged once and never thrown.
     */
    public function flush(): void
    {
        if ($this->inserts === [] && $this->upserts === []) {
            return;
        }

        [$inserts, $upserts] = [$this->inserts, $this->upserts];
        $this->inserts = $this->upserts = [];

        $this->ignore(function () use ($inserts, $upserts) {
            try {
                $db = $this->connection();

                // Inside an open transaction a failed statement would abort it on PostgreSQL;
                // a savepoint confines the failure to Spyglass.
                $db->transactionLevel() > 0
                    ? $db->transaction(fn () => $this->store($db, $inserts, $upserts))
                    : $this->store($db, $inserts, $upserts);
            } catch (Throwable $e) {
                try {
                    Log::error('Spyglass failed to store entries: '.$e->getMessage(), ['exception' => $e]);
                } catch (Throwable) {
                }
            }
        });
    }

    /**
     * Run the callback without recording, so storage and logging done by Spyglass itself never feed back into it.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function ignore(Closure $callback): mixed
    {
        $previous = $this->ignoring;
        $this->ignoring = true;

        try {
            return $callback();
        } finally {
            $this->ignoring = $previous;
        }
    }

    public function isIgnoring(): bool
    {
        return $this->ignoring;
    }

    /**
     * Enabled modules keyed by name.
     *
     * @internal
     *
     * @return array<string, Module>
     */
    public function modules(): array
    {
        $modules = [];

        foreach (static::$modules as $name => $class) {
            if (config("spyglass.modules.{$name}.enabled")) {
                $modules[$name] = app($class);
            }
        }

        return $modules;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $inserts
     * @param  array<string, array<string, array{key: non-empty-array<non-empty-string, mixed>, values: array<string, mixed>, increment: array<string, int|float>, max: array<string, mixed>}>>  $upserts
     */
    protected function store(Connection $db, array $inserts, array $upserts): void
    {
        foreach ($inserts as $table => $rows) {
            foreach ($this->groupByColumns($rows) as $group) {
                foreach (array_chunk($group, 500) as $chunk) {
                    $db->table($table)->insert($chunk);
                }
            }
        }

        foreach ($upserts as $table => $pending) {
            $groups = [];

            foreach ($pending as $entry) {
                $signature = array_map(function (array $columns) {
                    ksort($columns);

                    return array_keys($columns);
                }, $entry);

                $groups[serialize($signature)][] = $entry;
            }

            foreach ($groups as $group) {
                $first = $group[0];
                $update = array_keys($first['values']);

                foreach (array_keys($first['increment']) as $column) {
                    $update[$column] = $this->upsertExpression($db, $table, $column, 'increment');
                }

                foreach (array_keys($first['max']) as $column) {
                    $update[$column] = $this->upsertExpression($db, $table, $column, 'max');
                }

                $rows = array_map(fn (array $entry) => $entry['key'] + $entry['values'] + $entry['increment'] + $entry['max'], $group);

                foreach (array_chunk($rows, 500) as $chunk) {
                    $db->table($table)->upsert($chunk, array_keys($first['key']), $update);
                }
            }
        }
    }

    /**
     * Batch statements need identical columns on every row.
     *
     * @param  array<array-key, array<string, mixed>>  $rows
     * @return list<list<array<string, mixed>>>
     */
    protected function groupByColumns(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            ksort($row);
            $groups[implode(',', array_keys($row))][] = $row;
        }

        return array_values($groups);
    }

    /**
     * @param  'increment'|'max'  $operation
     */
    protected function upsertExpression(Connection $db, string $table, string $column, string $operation): ExpressionContract
    {
        $grammar = $db->getQueryGrammar();
        $current = $grammar->wrap($column);

        $existing = $grammar->wrapTable($table).'.'.$current;
        $incoming = match ($db->getDriverName()) {
            'mysql', 'mariadb' => $db->getConfig('use_upsert_alias')
                ? $grammar->wrap('laravel_upsert_alias').'.'.$current
                : "values({$current})",
            'pgsql', 'sqlite' => 'excluded.'.$current,
            default => throw new RuntimeException("Spyglass does not support the [{$db->getDriverName()}] database driver."),
        };

        // @phpstan-ignore argument.type (built from wrapped column names declared by modules, never from input)
        return new Expression($operation === 'increment'
            ? "{$existing} + {$incoming}"
            : ($db->getDriverName() === 'sqlite' ? 'max' : 'greatest')."({$existing}, {$incoming})");
    }
}
