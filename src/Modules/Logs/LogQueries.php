<?php

namespace Keepsuit\Spyglass\Modules\Logs;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Query\Builder;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Support\Hourly;
use Keepsuit\Spyglass\Support\Sources;
use stdClass;

/**
 * Read side of the logs module. Every query is bounded. Datetimes are UTC strings, as stored.
 */
class LogQueries
{
    public function __construct(protected Spyglass $spyglass) {}

    /**
     * Where a message came from, most frequent first, with exact counts: entries are throttled.
     * `$message` is the message as stored.
     *
     * @return array<int, stdClass> with `source_type`, `source`, `count` and `last_seen_at`
     */
    public function sources(string $level, string $message, int $limit = 100): array
    {
        return app(Sources::class)->of('log', LogsModule::signature($level, $message), $limit);
    }

    /**
     * An entry with the columns of `entries()`.
     */
    public function entry(int $id): ?object
    {
        return $this->entriesTable()->where('id', $id)->first();
    }

    /**
     * Entries, newest first. `log_context` is JSON cut at 4096 characters, so it may not decode.
     *
     * @param  array{level?: string, scope?: string, from?: CarbonInterface, to?: CarbonInterface}  $filters
     * @return Paginator<int, object>
     */
    public function entries(array $filters = [], int $perPage = 50, int $page = 1): Paginator
    {
        return $this->entriesTable()
            ->where(array_intersect_key($filters, array_flip(['level', 'scope'])))
            // Whole seconds at both ends: `to` takes in the milliseconds of its second.
            ->when($filters['from'] ?? null, fn (Builder $query, CarbonInterface $from) => $query->where('recorded_at', '>=', $from->copy()->utc()->startOfSecond()->format('Y-m-d H:i:s.v')))
            ->when($filters['to'] ?? null, fn (Builder $query, CarbonInterface $to) => $query->where('recorded_at', '<', $to->copy()->utc()->startOfSecond()->addSecond()->format('Y-m-d H:i:s.v')))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->simplePaginate(max(1, min($perPage, 100)), page: $page);
    }

    /**
     * Recorded entries per hour and level since `$from`, as `Hourly::counts()`. Throttled: at most one a
     * minute per message, scope and source.
     *
     * @param  array{level?: string, scope?: string}  $filters
     * @return list<array<string, mixed>>
     */
    public function hourly(CarbonInterface $from, array $filters = []): array
    {
        return Hourly::counts('log', $from, fn (Builder $query) => $query->where(array_intersect_key($filters, array_flip(['level', 'scope']))), by: 'level');
    }

    /**
     * Scopes of the entries, for filter suggestions.
     *
     * @return list<string>
     */
    public function scopes(int $limit = 100): array
    {
        return array_values($this->spyglass->connection()->table(LogsModule::ENTRIES)
            ->where('kind', 'log')
            ->whereNotNull('scope')
            ->distinct()
            ->orderBy('scope')
            ->limit(max(1, min($limit, 100)))
            ->pluck('scope')
            ->map(fn ($scope) => (string) $scope)
            ->all());
    }

    protected function entriesTable(): Builder
    {
        return $this->spyglass->connection()->table(LogsModule::ENTRIES)
            ->select(['id', 'level', 'message', 'body as log_context', 'recorded_at as logged_at', 'source_type', 'source', 'source_id', 'scope', 'context'])
            ->where('kind', 'log');
    }
}
