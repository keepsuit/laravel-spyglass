<?php

namespace Keepsuit\Spyglass\Modules\Exceptions;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Support\Sources;
use stdClass;

/**
 * Read side of the exceptions module. Every query is bounded. Datetimes are UTC strings, as stored.
 */
class ExceptionQueries
{
    public function __construct(protected Spyglass $spyglass) {}

    /**
     * Groups, last seen first. A group is resolved until an occurrence newer than `resolved_at`: occurrences
     * are stored after the end of their request or job, so storing one cannot tell whether it is newer.
     *
     * @param  array{status?: 'resolved'|'unresolved', class?: string, scope?: string}  $filters
     * @return Paginator<int, object>
     */
    public function groups(array $filters = [], int $perPage = 25, int $page = 1): Paginator
    {
        $groups = $this->groupsTable()
            ->when(($filters['status'] ?? null) === 'unresolved', fn (Builder $query) => $this->whereUnresolved($query))
            ->when(($filters['status'] ?? null) === 'resolved', fn (Builder $query) => $this->whereResolved($query))
            // `isset`, not truthiness: '0' is a valid class or scope filter.
            ->when(isset($filters['class']), fn (Builder $query) => $query->where('class', $filters['class'] ?? null))
            ->when(isset($filters['scope']), fn (Builder $query) => $query->whereExists(
                fn (Builder $occurrences) => $occurrences->from(ExceptionsModule::OCCURRENCES)
                    ->where(ExceptionsModule::OCCURRENCES.'.kind', 'exception')
                    ->where(ExceptionsModule::OCCURRENCES.'.scope', $filters['scope'] ?? null)
                    ->whereColumn(ExceptionsModule::OCCURRENCES.'.signature', ExceptionsModule::GROUPS.'.signature'),
            ))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->simplePaginate($this->clamp($perPage), page: $page);

        foreach ($groups->items() as $group) {
            $this->withStatus($group);
        }

        return $groups;
    }

    /**
     * `resolved_at` is null when the group is not resolved, as in `groups()`.
     */
    public function group(string $fingerprint): ?object
    {
        $group = $this->groupsTable()->where('signature', $fingerprint)->first();

        return $group === null ? null : $this->withStatus($group);
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
    public function occurrences(string $fingerprint, int $perPage = 25, int $page = 1): Paginator
    {
        return $this->spyglass->connection()->table(ExceptionsModule::OCCURRENCES)
            ->select([
                'id', 'signature as fingerprint', 'recorded_at as occurred_at', 'message', 'body as trace', 'cause_class', 'cause_message',
                'source_type', 'source', 'source_id', 'route', 'user_id', 'scope', 'context',
            ])
            ->where('kind', 'exception')
            ->where('signature', $fingerprint)
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
     * The total row of each group: the others count its sources.
     */
    protected function groupsTable(): Builder
    {
        return $this->spyglass->connection()->table(ExceptionsModule::GROUPS)
            ->select(['id', 'signature as fingerprint', 'class', 'message', 'file', 'line', 'count', 'first_seen_at', 'last_seen_at', 'resolved_at'])
            ->where('kind', 'exception')
            ->where('source_type', '');
    }

    protected function whereUnresolved(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereNull('resolved_at')->orWhereColumn('last_seen_at', '>', 'resolved_at'));
    }

    protected function whereResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at')->whereColumn('last_seen_at', '<=', 'resolved_at');
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
