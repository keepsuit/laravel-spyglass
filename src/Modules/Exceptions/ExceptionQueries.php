<?php

namespace Keepsuit\Spyglass\Modules\Exceptions;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Support\Buckets;
use Keepsuit\Spyglass\Support\Sources;
use stdClass;

/**
 * Read side of the exceptions module. Every query is bounded. Datetimes are UTC strings, as stored.
 */
class ExceptionQueries
{
    public function __construct(protected Spyglass $spyglass) {}

    /**
     * Groups by `$sort` (column, descending), then last seen first. A group is resolved until an occurrence newer than `resolved_at`: occurrences
     * are stored after the end of their request or job, so storing one cannot tell whether it is newer.
     *
     * With a scope, `count`, `first_seen_at`, `last_seen_at` and `message` come from the occurrences of that scope, which are throttled:
     * the count is a lower bound. The status stays the group's, across scopes.
     *
     * @param  array{status?: 'resolved'|'unresolved', class?: string, scope?: string}  $filters
     * @param  array{string, bool}|null  $sort  only trusted columns
     * @return Paginator<int, object>
     */
    public function groups(array $filters = [], int $perPage = 25, int $page = 1, ?array $sort = null): Paginator
    {
        // `isset`, not truthiness: '0' is a valid class or scope filter.
        $scope = $filters['scope'] ?? null;
        $table = $scope === null ? ExceptionsModule::GROUPS : 'scoped';
        $groups = $this->groupsTable()
            ->when($scope !== null, fn (Builder $query) => $query
                ->joinSub($this->scoped((string) $scope)
                    ->select('signature')
                    ->selectRaw('count(*) as count, min(recorded_at) as first_seen_at, max(recorded_at) as last_seen_at')
                    ->groupBy('signature'), 'scoped', 'scoped.signature', '=', ExceptionsModule::GROUPS.'.signature')
                ->addSelect(['scoped.count as scoped_count', 'scoped.first_seen_at as scoped_first_seen_at', 'scoped.last_seen_at as scoped_last_seen_at']))
            ->when(($filters['status'] ?? null) === 'unresolved', fn (Builder $query) => $this->whereUnresolved($query))
            ->when(($filters['status'] ?? null) === 'resolved', fn (Builder $query) => $this->whereResolved($query))
            ->when(isset($filters['class']), fn (Builder $query) => $query->where(ExceptionsModule::GROUPS.'.class', $filters['class'] ?? null))
            ->when($sort, fn (Builder $query, array $sort) => $query->orderBy("{$table}.{$sort[0]}", $sort[1] ? 'desc' : 'asc'))
            ->orderByDesc("{$table}.last_seen_at")
            ->orderByDesc(ExceptionsModule::GROUPS.'.id')
            ->simplePaginate($this->clamp($perPage), page: $page);

        foreach ($groups->items() as $group) {
            $this->withStatus($group);

            if ($scope !== null) {
                $this->withScope($group, [
                    'count' => data_get($group, 'scoped_count'),
                    'first_seen_at' => data_get($group, 'scoped_first_seen_at'),
                    'last_seen_at' => data_get($group, 'scoped_last_seen_at'),
                ], $scope);
            }
        }

        return $groups;
    }

    /**
     * `resolved_at` is null when the group is not resolved, as in `groups()`. With a scope, as in `groups()`: a group
     * without occurrences in it has a count of 0.
     */
    public function group(string $fingerprint, ?string $scope = null): ?object
    {
        $group = $this->groupsTable()->where('signature', $fingerprint)->first();

        if ($group === null) {
            return null;
        }

        $this->withStatus($group);

        return $scope === null ? $group : $this->withScope($group, (array) $this->scoped($scope)
            ->where('signature', $fingerprint)
            ->selectRaw('count(*) as count, min(recorded_at) as first_seen_at, max(recorded_at) as last_seen_at')
            ->first(), $scope);
    }

    /**
     * Where the group came from, most frequent first, with exact counts: occurrences are throttled.
     *
     * @return array<int, stdClass>
     */
    public function sources(string $fingerprint, int $limit = 100): array
    {
        return app(Sources::class)->of('exception', $fingerprint, $limit);
    }

    /**
     * Stored occurrences of a group, newest first. `trace` is a JSON list of `{file, line, function}`.
     *
     * @return Paginator<int, object>
     */
    public function occurrences(string $fingerprint, int $perPage = 25, int $page = 1, ?string $scope = null): Paginator
    {
        return $this->spyglass->connection()->table(ExceptionsModule::OCCURRENCES)
            ->select([
                'id', 'signature as fingerprint', 'recorded_at as occurred_at', 'message', 'body as trace', 'cause_class', 'cause_message',
                'source_type', 'source', 'source_id', 'route', 'user_id', 'scope', 'context',
            ])
            ->where('kind', 'exception')
            ->where('signature', $fingerprint)
            ->when($scope !== null, fn (Builder $query) => $query->where('scope', $scope))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->simplePaginate($this->clamp($perPage), page: $page);
    }

    /**
     * An occurrence newer than the resolution reopens the group.
     */
    public function resolve(string $fingerprint): bool
    {
        return $this->whereUnresolved($this->groupsTable()->where('signature', $fingerprint))
            ->update(['resolved_at' => Date::now('UTC')->format('Y-m-d H:i:s.v')]) > 0;
    }

    public function unresolve(string $fingerprint): bool
    {
        return $this->whereResolved($this->groupsTable()->where('signature', $fingerprint))
            ->update(['resolved_at' => null]) > 0;
    }

    /**
     * Recorded occurrences per hour since `$from`, as `Buckets::entries()`. Throttled: at most one a minute per
     * exception and scope.
     *
     * @param  array{class?: string, scope?: string}  $filters
     * @return list<array<string, mixed>>
     */
    public function hourly(CarbonInterface $from, array $filters = []): array
    {
        return Buckets::entries('exception', $from, fn (Builder $query) => $query
            ->when(isset($filters['scope']), fn (Builder $query) => $query->where('scope', $filters['scope'] ?? null))
            ->when(isset($filters['class']), fn (Builder $query) => $query->whereIn('signature', fn (Builder $groups) => $groups
                ->from(ExceptionsModule::GROUPS)
                ->select('signature')
                ->where('kind', 'exception')
                ->where('source_type', '')
                ->where('class', $filters['class'] ?? null))));
    }

    /**
     * Exception classes of the groups, for filter suggestions.
     *
     * @return list<string>
     */
    public function classes(int $limit = 100): array
    {
        return array_values($this->spyglass->connection()->table(ExceptionsModule::GROUPS)
            ->where('kind', 'exception')
            ->where('source_type', '')
            ->whereNotNull('class')
            ->distinct()
            ->orderBy('class')
            ->limit($this->clamp($limit))
            ->pluck('class')
            ->map(fn ($class) => (string) $class)
            ->all());
    }

    /**
     * Scopes of the occurrences, for filter suggestions.
     *
     * @return list<string>
     */
    public function scopes(int $limit = 100): array
    {
        return array_values($this->spyglass->connection()->table(ExceptionsModule::OCCURRENCES)
            ->where('kind', 'exception')
            ->whereNotNull('scope')
            ->distinct()
            ->orderBy('scope')
            ->limit($this->clamp($limit))
            ->pluck('scope')
            ->map(fn ($scope) => (string) $scope)
            ->all());
    }

    /**
     * The total row of each group: the others count its sources. Qualified, as `groups()` joins the occurrences.
     */
    protected function groupsTable(): Builder
    {
        $columns = ['id', 'signature as fingerprint', 'class', 'message', 'file', 'line', 'count', 'first_seen_at', 'last_seen_at', 'resolved_at'];

        return $this->spyglass->connection()->table(ExceptionsModule::GROUPS)
            ->select(array_map(fn (string $column) => ExceptionsModule::GROUPS.'.'.$column, $columns))
            ->where(ExceptionsModule::GROUPS.'.kind', 'exception')
            ->where(ExceptionsModule::GROUPS.'.source_type', '');
    }

    protected function scoped(string $scope): Builder
    {
        return $this->spyglass->connection()->table(ExceptionsModule::OCCURRENCES)->where('kind', 'exception')->where('scope', $scope);
    }

    /**
     * Replaces the totals of the group with those of `$scope`, the message with its latest there. After `withStatus()`:
     * the status is the group's.
     *
     * @param  array<string, mixed>  $totals  count, first_seen_at, last_seen_at
     */
    protected function withScope(object $group, array $totals, string $scope): object
    {
        foreach (['scoped_count', 'scoped_first_seen_at', 'scoped_last_seen_at'] as $key) {
            unset($group->{$key});
        }

        foreach ($totals as $key => $value) {
            data_set($group, $key, $key === 'count' ? (int) $value : $value);
        }

        // ponytail: a query per group of the page, bounded by its size; one query with a window function if it shows.
        data_set($group, 'message', $this->scoped($scope)
            ->where('signature', data_get($group, 'fingerprint'))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->value('message'));

        return $group;
    }

    protected function whereUnresolved(Builder $query): Builder
    {
        $groups = ExceptionsModule::GROUPS;

        return $query->where(fn (Builder $query) => $query->whereNull("{$groups}.resolved_at")->orWhereColumn("{$groups}.last_seen_at", '>', "{$groups}.resolved_at"));
    }

    protected function whereResolved(Builder $query): Builder
    {
        $groups = ExceptionsModule::GROUPS;

        return $query->whereNotNull("{$groups}.resolved_at")->whereColumn("{$groups}.last_seen_at", '<=', "{$groups}.resolved_at");
    }

    protected function withStatus(object $group): object
    {
        $resolvedAt = data_get($group, 'resolved_at');

        if ($resolvedAt !== null && data_get($group, 'last_seen_at') > $resolvedAt) {
            data_set($group, 'resolved_at', null);
        }

        return $group;
    }

    /**
     * @return positive-int
     */
    protected function clamp(int $perPage): int
    {
        return max(1, min($perPage, 100));
    }
}
