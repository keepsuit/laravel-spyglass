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

    public const int MAX_QUEUES = 100;

    protected const array COUNTERS = ['queued', 'processed', 'failed', 'released', 'timed_out', 'duration_sum', 'duration_max'];

    public function __construct(protected Spyglass $spyglass) {}

    /**
     * Counters per 5-minute bucket, oldest first.
     *
     * @param  list<'queue'|'job'>  $groupBy
     * @param  array{queue?: string, job?: string, scope?: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function series(CarbonInterface $from, CarbonInterface $to, array $groupBy = [], array $filters = []): Collection
    {
        return $this->aggregates($from, $to, ['bucket', ...$this->groupColumns($groupBy)], $filters)
            ->orderBy('bucket')
            ->limit(self::MAX_SERIES_ROWS)
            ->get()
            ->map($this->castCounters(...));
    }

    /**
     * Counters over the whole range, busiest first.
     *
     * @param  list<'queue'|'job'>  $groupBy
     * @param  array{queue?: string, job?: string, scope?: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function totals(CarbonInterface $from, CarbonInterface $to, array $groupBy = ['queue'], array $filters = [], int $limit = 100): Collection
    {
        return $this->aggregates($from, $to, $this->groupColumns($groupBy), $filters)
            ->orderByRaw('sum(processed) + sum(failed) + sum(released) + sum(timed_out) desc')
            ->limit(min($limit, 1000))
            ->get()
            ->map($this->castCounters(...));
    }

    /**
     * Failed jobs index, newest first. The payload and the stack trace stay in the failer: see `failedJob()`.
     *
     * @param  array{queue?: string, job?: string, scope?: string}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function failedJobs(array $filters = [], int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        return $this->spyglass->connection()->table(QueueModule::FAILED_JOBS)
            ->where($this->filters($filters))
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->paginate(max(1, min($perPage, 100)), page: $page);
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
        return $this->spyglass->connection()->table(QueueModule::AGGREGATES)
            ->where('bucket', '>=', $since->toDateTimeString())
            ->distinct()
            ->orderBy('connection')
            ->orderBy('queue')
            ->limit(self::MAX_QUEUES)
            ->get(['connection', 'queue'])
            ->map(fn (object $row) => $this->queueSizes($row->connection, $row->queue));
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
        // `queue:retry` exits with 0 for an unknown id.
        if (app(FailedJobProviderInterface::class)->find($uuid) === null) {
            $this->forgetIndexRow($uuid);

            return Outcome::Missing;
        }

        try {
            $exitCode = Artisan::call($command, ['id' => [$uuid]]);
        } catch (Throwable $e) {
            report($e);

            return Outcome::Failed;
        }

        if ($exitCode !== 0) {
            return Outcome::Failed;
        }

        // Both commands remove the job from the failer. A retried job that fails again gets a new row.
        $this->forgetIndexRow($uuid);

        return Outcome::Done;
    }

    protected function forgetIndexRow(string $uuid): void
    {
        $this->spyglass->connection()->table(QueueModule::FAILED_JOBS)->where('uuid', $uuid)->delete();
    }

    /**
     * @param  list<string>  $groups
     * @param  array{queue?: string, job?: string, scope?: string}  $filters
     */
    protected function aggregates(CarbonInterface $from, CarbonInterface $to, array $groups, array $filters): Builder
    {
        return $this->spyglass->connection()->table(QueueModule::AGGREGATES)
            ->select($groups)
            ->selectRaw('sum(queued) as queued, sum(processed) as processed, sum(failed) as failed, sum(released) as released, '
                .'sum(timed_out) as timed_out, sum(duration_sum) as duration_sum, max(duration_max) as duration_max')
            ->whereBetween('bucket', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->where($this->filters($filters))
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
     * @param  array{queue?: string, job?: string, scope?: string}  $filters
     * @return array<string, string>
     */
    protected function filters(array $filters): array
    {
        return array_intersect_key($filters, array_flip(['queue', 'job', 'scope']));
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
