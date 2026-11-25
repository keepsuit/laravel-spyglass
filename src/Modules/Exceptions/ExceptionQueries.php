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
    /** Groups considered by the scope filter. */
    public const int MAX_SCOPE_GROUPS = 1000;

    public function __construct(protected Spyglass $spyglass) {}

    /**
     * Groups, last seen first.
     *
     * @param  array{status?: 'resolved'|'unresolved', class?: string, scope?: string}  $filters
     * @return Paginator<int, object>
     */
    public function groups(array $filters = [], int $perPage = 25, int $page = 1): Paginator
    {
        return $this->spyglass->connection()->table(ExceptionsModule::GROUPS)
            ->when(($filters['status'] ?? null) === 'unresolved', fn (Builder $query) => $query->whereNull('resolved_at'))
            ->when(($filters['status'] ?? null) === 'resolved', fn (Builder $query) => $query->whereNotNull('resolved_at'))
            ->when($filters['class'] ?? null, fn (Builder $query, string $class) => $query->where('class', $class))
            ->when($filters['scope'] ?? null, fn (Builder $query, string $scope) => $query->whereIn('fingerprint', $this->fingerprintsIn($scope)))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->simplePaginate($this->clamp($perPage), page: $page);
    }

    public function group(string $fingerprint): ?object
    {
        return $this->spyglass->connection()->table(ExceptionsModule::GROUPS)->where('fingerprint', $fingerprint)->first();
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
     * A new occurrence reopens the group.
     */
    public function resolve(string $fingerprint): bool
    {
        return $this->spyglass->connection()->table(ExceptionsModule::GROUPS)
            ->where('fingerprint', $fingerprint)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => Date::now('UTC')->toDateTimeString()]) > 0;
    }

    public function unresolve(string $fingerprint): bool
    {
        return $this->spyglass->connection()->table(ExceptionsModule::GROUPS)
            ->where('fingerprint', $fingerprint)
            ->whereNotNull('resolved_at')
            ->update(['resolved_at' => null]) > 0;
    }

    /**
     * A separate bounded query: MySQL does not allow `LIMIT` in an `IN` subquery.
     *
     * @return array<mixed>
     */
    protected function fingerprintsIn(string $scope): array
    {
        return $this->spyglass->connection()->table(ExceptionsModule::OCCURRENCES)
            ->where('scope', $scope)
            ->distinct()
            ->limit(self::MAX_SCOPE_GROUPS)
            ->pluck('fingerprint')
            ->all();
    }

    /**
     * @return positive-int
     */
    protected function clamp(int $perPage): int
    {
        return max(1, min($perPage, 100));
    }
}
