<?php

namespace Keepsuit\Spyglass\Modules\Queue;

use Closure;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Module;
use Keepsuit\Spyglass\Support\ExceptionFingerprint;
use Throwable;
use WeakMap;

use function Illuminate\Support\enum_value;

/**
 * Sync jobs are left out: they are never queued and never reach the failer.
 */
class QueueModule extends Module
{
    public const string AGGREGATES = 'spyglass_queue_aggregates';

    public const string FAILED_JOBS = 'spyglass_queue_failed_jobs';

    /**
     * Attempts in progress, until counted. Keyed by the job instance, which is the same object
     * in every event of an attempt, so nested sync jobs and a missed end event cannot leak state.
     *
     * @var WeakMap<Job, array{started: int|float, queue: string, scope: ?string, context: ?string}>
     */
    protected WeakMap $attempts;

    /**
     * The queues this worker was started on, as the app names them.
     *
     * @var array{string, string}|null [connection, comma-separated queues]
     */
    protected ?array $workerQueues = null;

    public function __construct()
    {
        $this->attempts = new WeakMap;
    }

    public function boot(): void
    {
        $this->listen(JobQueued::class, function (JobQueued $event) {
            $payload = json_decode($event->payload, true);

            $this->count(
                $event->connectionName,
                $this->queueName($event->connectionName, $event->queue),
                $this->jobName($payload),
                'queued',
                config('spyglass.modules.queue.aggregate_by_scope') ? Spyglass::captureContext()['scope'] : null,
            );
        });

        $this->listen(WorkerStarting::class, fn (WorkerStarting $event) => $this->workerQueues = [$event->connectionName, (string) $event->queue]);

        // Carries the queues since Laravel 13, also for `queue:work --once`, which skips `WorkerStarting`.
        $this->listen(JobPopping::class, function (JobPopping $event) {
            if (isset($event->queue)) {
                $this->workerQueues = [$event->connectionName, (string) $event->queue];
            }
        });

        // Laravel hydrates the Context from the payload before this runs. Tenancy may revert it before the job fails.
        $this->listen(JobProcessing::class, function (JobProcessing $event) {
            if (! $event->job instanceof SyncJob) {
                $this->attempts[$event->job] = ['started' => hrtime(true), 'queue' => $this->workerQueueName($event->job), ...Spyglass::captureContext()];
            }
        });

        $this->listen(JobFailed::class, fn (JobFailed $event) => $this->recordFailure($event, app(FailedJobProviderInterface::class)));

        // `JobProcessed` also fires after a manual `fail()` or `release()` and when max tries are exceeded,
        // so the outcome is read from the job state when the attempt ends. A job that deleted itself and
        // then threw is neither failed nor released.
        $this->listen(JobAttempted::class, fn (JobAttempted $event) => $this->countAttempt(
            $event->job,
            match (true) {
                $event->job->hasFailed() => 'failed',
                $event->job->isReleased() => 'released',
                ! $event->successful() => 'failed',
                default => 'processed',
            },
        ));

        // On timeout the worker exits right after this event, so `JobAttempted` never fires and the
        // attempt, and the failed row of a last try, are stored now.
        $this->listen(JobTimedOut::class, function (JobTimedOut $event) {
            $this->countAttempt($event->job, $event->job->hasFailed() ? 'failed' : 'timed_out');

            Spyglass::flush();
        });
    }

    /**
     * The same queue name on dispatch and in the worker. SQS workers report the queue URL.
     */
    protected function queueName(string $connection, mixed $queue): string
    {
        $queue = (string) (enum_value($queue) ?? config("queue.connections.{$connection}.queue") ?? 'default');

        return filter_var($queue, FILTER_VALIDATE_URL) === false ? $queue : Str::afterLast(rtrim($queue, '/'), '/');
    }

    /**
     * Jobs report the queue the driver stores them in: an SQS URL with prefix and suffix, the target of a
     * queue forward. The worker pops its queues in order, so the first one stored where the job was is its own.
     */
    protected function workerQueueName(Job $job): string
    {
        $connection = $job->getConnectionName();
        $stored = $job->getQueue();

        if ($this->workerQueues !== null && $this->workerQueues[0] === $connection) {
            $queue = Queue::connection($connection);

            foreach (explode(',', $this->workerQueues[1]) as $name) {
                if ($name === $stored || (method_exists($queue, 'getQueue') && $queue->getQueue($name) === $stored)) {
                    return $this->queueName($connection, $name);
                }
            }
        }

        return $this->queueName($connection, $stored);
    }

    /**
     * The job class, since a custom `displayName()` may embed ids. Framework wrappers (queued listeners,
     * mailables, notifications, ...) are named after the class they wrap, as their display name is.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function jobName(array $payload): string
    {
        $class = $payload['data']['commandName'] ?? null;

        return is_string($class) && ! str_starts_with($class, 'Illuminate\\')
            ? $class
            : (string) ($payload['displayName'] ?? $class ?? $payload['job'] ?? 'unknown');
    }

    /**
     * Recording must never break the host: an exception in a `JobQueued` listener reaches the dispatching
     * code after the job is stored, and one in `JobFailed` would skip the failer, whose listener runs later.
     *
     * @param  class-string  $event
     */
    protected function listen(string $event, Closure $listener): void
    {
        Event::listen($event, function (object $payload) use ($listener) {
            try {
                $listener($payload);
            } catch (Throwable $e) {
                Spyglass::report('Spyglass failed to record a queue event', $e);
            }
        });
    }

    public function prunable(): array
    {
        return [
            self::AGGREGATES => ['column' => 'bucket', 'retention' => 'aggregates'],
            self::FAILED_JOBS => ['column' => 'failed_at', 'retention' => 'failed_jobs'],
        ];
    }

    /**
     * Remove index rows of failed jobs no longer in the failer (retried or deleted outside Spyglass).
     */
    public function prune(): array
    {
        return $this->pruneIndex(app(FailedJobProviderInterface::class));
    }

    /**
     * @return array<string, int>
     */
    protected function pruneIndex(FailedJobProviderInterface $failer): array
    {
        $db = Spyglass::connection();
        // Rows indexed after the ids are read would look missing.
        $lastId = $db->table(self::FAILED_JOBS)->max('id');
        // Other providers may return a partial list, e.g. DynamoDB one page of results.
        $ids = $failer instanceof DatabaseUuidFailedJobProvider || $failer instanceof FileFailedJobProvider
            ? array_flip(array_map(strval(...), $failer->ids()))
            : null;
        $deleted = 0;

        $db->table(self::FAILED_JOBS)->select(['id', 'uuid'])->where('id', '<=', $lastId ?? 0)->chunkById(500, function ($rows) use ($failer, $ids, $db, &$deleted) {
            $missing = $rows->filter(fn (object $row) => $ids === null ? $failer->find($row->uuid) === null : ! isset($ids[$row->uuid]))->pluck('id');

            if ($missing->isNotEmpty()) {
                $deleted += $db->table(self::FAILED_JOBS)->whereIn('id', $missing)->delete();
            }
        });

        return [self::FAILED_JOBS => $deleted];
    }

    protected function recordFailure(JobFailed $event, FailedJobProviderInterface $failer): void
    {
        $job = $event->job;
        $uuid = $job->uuid();

        // The index mirrors the failer by uuid. The null failer stores nothing, the legacy database one
        // is keyed by an auto-increment id.
        if ($job instanceof SyncJob || $uuid === null || $failer instanceof NullFailedJobProvider || $failer instanceof DatabaseFailedJobProvider) {
            return;
        }

        $snapshot = $this->attempts[$job] ?? ['queue' => $this->workerQueueName($job), ...Spyglass::captureContext()];

        // `queue:retry` keeps the uuid, so a retried job that fails again updates its row.
        Spyglass::upsert(self::FAILED_JOBS, ['uuid' => $uuid], values: [
            'connection' => mb_substr($event->connectionName, 0, 64),
            'queue' => mb_substr($snapshot['queue'], 0, 191),
            'job' => mb_substr($this->jobName($job->payload()), 0, 255),
            'attempts' => $job->attempts(),
            'exception_class' => mb_substr($event->exception::class, 0, 255),
            'fingerprint' => ExceptionFingerprint::for($event->exception),
            'scope' => $snapshot['scope'] === null ? null : mb_substr($snapshot['scope'], 0, 191),
            'context' => $snapshot['context'],
            'failed_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * @param  'processed'|'failed'|'released'|'timed_out'  $outcome
     */
    protected function countAttempt(Job $job, string $outcome): void
    {
        // Counted once: a timeout can be followed by `JobAttempted` when the worker is not killed.
        if (! isset($this->attempts[$job])) {
            return;
        }

        $attempt = $this->attempts[$job];
        unset($this->attempts[$job]);

        $this->count(
            $job->getConnectionName(),
            $attempt['queue'],
            $this->jobName($job->payload()),
            $outcome,
            $attempt['scope'],
            (int) round((hrtime(true) - $attempt['started']) / 1e6),
        );
    }

    /**
     * @param  'queued'|'processed'|'failed'|'released'|'timed_out'  $counter
     */
    protected function count(string $connection, string $queue, string $job, string $counter, ?string $scope, ?int $duration = null): void
    {
        // UTC, so the repeated hour when DST ends does not merge two buckets.
        $now = now()->utc();

        // Every counter on every row: one column shape, so one statement per flush.
        $increment = [$counter => 1, 'duration_sum' => $duration ?? 0] + ['queued' => 0, 'processed' => 0, 'failed' => 0, 'released' => 0, 'timed_out' => 0];
        $key = [
            // A string, so keys of the same bucket merge in the buffer.
            'bucket' => $now->setTimestamp(intdiv($now->getTimestamp(), 300) * 300)->toDateTimeString(),
            'connection' => mb_substr($connection, 0, 64),
            'queue' => mb_substr($queue, 0, 191),
            'job' => mb_substr($job, 0, 255),
        ];

        // The unscoped row is the total of every scope, so unfiltered reads never sum scoped rows.
        Spyglass::upsert(self::AGGREGATES, [...$key, 'scope' => ''], increment: $increment, max: ['duration_max' => $duration ?? 0]);

        if ($scope !== null && $scope !== '' && config('spyglass.modules.queue.aggregate_by_scope')) {
            Spyglass::upsert(self::AGGREGATES, [...$key, 'scope' => mb_substr($scope, 0, 191)], increment: $increment, max: ['duration_max' => $duration ?? 0]);
        }
    }
}
