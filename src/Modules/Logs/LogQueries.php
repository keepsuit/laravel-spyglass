<?php

namespace Keepsuit\Spyglass\Modules\Logs;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Query\Builder;
use Keepsuit\Spyglass\Spyglass;
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
     * Entries, newest first. `log_context` is JSON cut at 4 KB, so it may not decode.
     *
     * @param  array{level?: string, scope?: string, from?: CarbonInterface, to?: CarbonInterface}  $filters
     * @return Paginator<int, object>
     */
    public function entries(array $filters = [], int $perPage = 50, int $page = 1): Paginator
    {
        return $this->spyglass->connection()->table(LogsModule::ENTRIES)
            ->where(array_intersect_key($filters, array_flip(['level', 'scope'])))
            ->when($filters['from'] ?? null, fn (Builder $query, CarbonInterface $from) => $query->where('logged_at', '>=', $from->copy()->utc()->toDateTimeString()))
            ->when($filters['to'] ?? null, fn (Builder $query, CarbonInterface $to) => $query->where('logged_at', '<=', $to->copy()->utc()->toDateTimeString()))
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->simplePaginate(max(1, min($perPage, 100)), page: $page);
    }
}
