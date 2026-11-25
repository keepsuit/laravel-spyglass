<?php

namespace Keepsuit\Spyglass\Modules\Exceptions;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Keepsuit\Spyglass\Spyglass;

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
        $groups = $this->spyglass->connection()->table(ExceptionsModule::GROUPS)
            ->when(($filters['status'] ?? null) === 'unresolved', fn (Builder $query) => $this->whereUnresolved($query))
            ->when(($filters['status'] ?? null) === 'resolved', fn (Builder $query) => $this->whereResolved($query))
            ->when($filters['class'] ?? null, fn (Builder $query, string $class) => $query->where('class', $class))
            ->when($filters['scope'] ?? null, fn (Builder $query, string $scope) => $query->whereExists(
                fn (Builder $occurrences) => $occurrences->from(ExceptionsModule::OCCURRENCES)
                    ->whereColumn(ExceptionsModule::OCCURRENCES.'.fingerprint', ExceptionsModule::GROUPS.'.fingerprint')
                    ->where('scope', $scope),
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
        $group = $this->spyglass->connection()->table(ExceptionsModule::GROUPS)->where('fingerprint', $fingerprint)->first();

        return $group === null ? null : $this->withStatus($group);
    }

    /**
     * Stored occurrences of a group, newest first. `trace` is a JSON list of `{file, line, function}`.
     *
     * @return Paginator<int, object>
     */
    public function occurrences(string $fingerprint, int $perPage = 25, int $page = 1): Paginator
    {
        return $this->spyglass->connection()->table(ExceptionsModule::OCCURRENCES)
            ->where('fingerprint', $fingerprint)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->simplePaginate($this->clamp($perPage), page: $page);
    }

    /**
     * An occurrence newer than the resolution reopens the group.
     */
    public function resolve(string $fingerprint): bool
    {
        return $this->whereUnresolved($this->spyglass->connection()->table(ExceptionsModule::GROUPS)->where('fingerprint', $fingerprint))
            ->update(['resolved_at' => Date::now('UTC')->toDateTimeString()]) > 0;
    }

    public function unresolve(string $fingerprint): bool
    {
        return $this->whereResolved($this->spyglass->connection()->table(ExceptionsModule::GROUPS)->where('fingerprint', $fingerprint))
            ->update(['resolved_at' => null]) > 0;
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
