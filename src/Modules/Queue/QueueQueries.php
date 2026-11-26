<?php

namespace Keepsuit\Spyglass\Modules\Queue;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Keepsuit\Spyglass\Spyglass;
use Throwable;

/**
 * Read side of the queue module. Every query is bounded.
 */
class QueueQueries
{
    public const int MAX_SERIES_ROWS = 5000;

    public const int MAX_QUEUES = 20;

    protected const array COUNTERS = ['queued', 'processed', 'failed', 'released', 'timed_out', 'duration_sum', 'duration_max'];

    public function __construct(protected Spyglass $spyglass) {}

    /**
     * Counters per 5-minute bucket, oldest first, with buckets in UTC. Beyond the row limit the oldest buckets are left out.
     *
     * @param  list<'queue'|'job'>  $groupBy
     * @param  array{connection?: string, queue?: string, job?: string, scope?: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function series(CarbonInterface $from, CarbonInterface $to, array $groupBy = [], array $filters = []): Collection
    {
        $rows = $this->aggregates($from, $to, ['bucket', ...$this->groupColumns($groupBy)], $filters)
            ->orderByDesc('bucket')
            // One more row tells whether the limit cut the oldest bucket, which would show partial counters.
            ->limit(self::MAX_SERIES_ROWS + 1)
            ->get();

        if ($rows->count() > self::MAX_SERIES_ROWS) {
            $oldest = $rows->pop()?->bucket;
            $rows = $rows->reject(fn (object $row) => $row->bucket === $oldest);
        }

        return $rows->reverse()->values()->map($this->castCounters(...));
    }

    /**
     * Counters over the whole range, busiest first.
     *
     * @param  list<'queue'|'job'>  $groupBy
     * @param  array{connection?: string, queue?: string, job?: string, scope?: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function totals(CarbonInterface $from, CarbonInterface $to, array $groupBy = ['queue'], array $filters = [], int $limit = 100): Collection
    {
        return $this->aggregates($from, $to, $this->groupColumns($groupBy), $filters)
            ->orderByRaw('sum(processed) + sum(failed) + sum(released) + sum(timed_out) desc')
            ->limit($this->clamp($limit, 1000))
            ->get()
            ->map($this->castCounters(...));
    }

    /**
     * Failed jobs index, newest first. The payload and the stack trace stay in the failer: see `failedJob()`.
     *
     * @param  array{connection?: string, queue?: string, job?: string, scope?: string}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function failedJobs(array $filters = [], int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        return $this->spyglass->connection()->table(QueueModule::FAILED_JOBS)
            ->where($this->filters($filters))
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->paginate($this->clamp($perPage, 100), page: $page);
    }

    /**
     * The failer record, with payload and exception. Null when it is gone, and the index row is removed.
     */
    public function failedJob(string $uuid): ?object
    {
        $record = app(FailedJobProviderInterface::class)->find($uuid);

        if ($record === null) {
            $this->forgetIndexRow($uuid);
        }

        return $record;
    }

    /**
     * Current sizes of the queues seen since `$since`. A size is null when the driver cannot report it.
     *
     * @return Collection<int, array{connection: string, queue: string, pending: ?int, reserved: ?int, delayed: ?int}>
     */
    public function sizes(CarbonInterface $since): Collection
    {
        // Each size is a call to the queue backend, repeated by every dashboard refresh.
        return collect($this->spyglass->cache()->remember(
            'spyglass:queue:sizes:'.intdiv($since->getTimestamp(), 300),
            5,
            fn () => $this->spyglass->connection()->table(QueueModule::AGGREGATES)
                ->where('scope', '')
                ->where('bucket', '>=', $this->utc($since))
                ->distinct()
                ->orderBy('connection')
                ->orderBy('queue')
                ->limit(self::MAX_QUEUES)
                ->get(['connection', 'queue'])
                ->map(fn (object $row) => $this->queueSizes($row->connection, $row->queue))
                ->all(),
        ));
    }

    /**
     * @return array{connection: string, queue: string, pending: ?int, reserved: ?int, delayed: ?int}
     */
    protected function queueSizes(string $connection, string $queue): array
    {
        return [
            'connection' => $connection,
            'queue' => $queue,
            'pending' => $this->size(fn () => Queue::connection($connection)->pendingSize($queue)),
            'reserved' => $this->size(fn () => Queue::connection($connection)->reservedSize($queue)),
            'delayed' => $this->size(fn () => Queue::connection($connection)->delayedSize($queue)),
        ];
    }

    public function retry(string $uuid): Outcome
    {
        return $this->runOnFailedJob('queue:retry', $uuid);
    }

    public function forget(string $uuid): Outcome
    {
        return $this->runOnFailedJob('queue:forget', $uuid);
    }

    protected function runOnFailedJob(string $command, string $uuid): Outcome
    {
        $failer = app(FailedJobProviderInterface::class);
        $record = $failer->find($uuid);

        // `queue:retry` exits with 0 for an unknown id.
        if ($record === null) {
            $this->forgetIndexRow($uuid);

            return Outcome::Missing;
        }

        $startedAt = now();

        try {
            // A single id: `queue:forget` takes one on Laravel 12 and casts it to a list on 13.
            $exitCode = Artisan::call($command, ['id' => $uuid]);
        } catch (Throwable $e) {
            report($e);

            return Outcome::Failed;
        }

        $gone = $failer->find($uuid) === null;

        if ($exitCode !== 0 || ($command === 'queue:forget' && ! $gone)) {
            return Outcome::Failed;
        }

        // A retried job may already have failed again, with the same uuid: its row stays.
        if ($gone) {
            $this->forgetIndexRow($uuid, $startedAt);
        }

        return Outcome::Done;
    }

    protected function forgetIndexRow(string $uuid, ?CarbonInterface $failedBefore = null): void
    {
        $this->spyglass->connection()->table(QueueModule::FAILED_JOBS)
            ->where('uuid', $uuid)
            ->when($failedBefore, fn (Builder $query, CarbonInterface $before) => $query->where('failed_at', '<=', $before->toDateTimeString()))
            ->delete();
    }

    /**
     * @param  list<string>  $groups
     * @param  array{connection?: string, queue?: string, job?: string, scope?: string}  $filters
     */
    protected function aggregates(CarbonInterface $from, CarbonInterface $to, array $groups, array $filters): Builder
    {
        return $this->spyglass->connection()->table(QueueModule::AGGREGATES)
            ->select($groups)
            ->selectRaw('sum(queued) as queued, sum(processed) as processed, sum(failed) as failed, sum(released) as released, '
                .'sum(timed_out) as timed_out, sum(duration_sum) as duration_sum, max(duration_max) as duration_max')
            ->whereBetween('bucket', [$this->utc($from), $this->utc($to)])
            // Unfiltered reads take the total rows: summing scoped rows too would count jobs twice.
            ->where($this->filters($filters + ['scope' => '']))
            ->groupBy($groups);
    }

    /**
     * Queue names are only unique within a connection.
     *
     * @param  list<'queue'|'job'>  $groupBy
     * @return list<string>
     */
    protected function groupColumns(array $groupBy): array
    {
        $columns = [];

        foreach ($groupBy as $group) {
            array_push($columns, ...($group === 'queue' ? ['connection', 'queue'] : ['job']));
        }

        return $columns;
    }

    /**
     * @param  array{connection?: string, queue?: string, job?: string, scope?: string}  $filters
     * @return array<string, string>
     */
    protected function filters(array $filters): array
    {
        return array_intersect_key($filters, array_flip(['connection', 'queue', 'job', 'scope']));
    }

    /**
     * Sums come back as strings on some drivers.
     *
     * @return array<string, mixed>
     */
    protected function castCounters(object $row): array
    {
        $row = (array) $row;

        foreach (self::COUNTERS as $column) {
            $row[$column] = (int) $row[$column];
        }

        return $row;
    }

    /**
     * Laravel ignores a negative limit, which would drop the `LIMIT` clause.
     *
     * @return positive-int
     */
    protected function clamp(int $limit, int $max): int
    {
        return max(1, min($limit, $max));
    }

    protected function utc(CarbonInterface $time): string
    {
        return $time->copy()->utc()->toDateTimeString();
    }

    /**
     * @param  callable(): int  $size
     */
    protected function size(callable $size): ?int
    {
        try {
            return $size();
        } catch (Throwable) {
            return null;
        }
    }
}
