<?php

namespace Keepsuit\Spyglass\Modules\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Queue\Jobs\JobName;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Module;
use Keepsuit\Spyglass\Support\ExceptionFingerprint;
use WeakMap;

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
     * @var WeakMap<Job, array{started: int|float, scope: ?string, context: ?string}>
     */
    protected WeakMap $attempts;

    public function __construct()
    {
        $this->attempts = new WeakMap;
    }

    public function boot(): void
    {
        Event::listen(JobQueued::class, function (JobQueued $event) {
            $payload = json_decode($event->payload, true);

            $this->count(
                $event->connectionName,
                $event->queue ?? config("queue.connections.{$event->connectionName}.queue") ?? 'default',
                JobName::resolve($payload['job'] ?? '', $payload),
                'queued',
                config('spyglass.modules.queue.aggregate_by_scope') ? Spyglass::captureContext()['scope'] : null,
            );
        });

        // Laravel hydrates the Context from the payload before this runs. Tenancy may revert it before the job fails.
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            if (! $event->job instanceof SyncJob) {
                $this->attempts[$event->job] = ['started' => hrtime(true), ...Spyglass::captureContext()];
            }
        });

        Event::listen(JobFailed::class, fn (JobFailed $event) => $this->recordFailure($event, app(FailedJobProviderInterface::class)));

        // `JobProcessed` also fires after a manual `fail()` or `release()` and when max tries are exceeded,
        // so the outcome is read from the job state when the attempt ends.
        Event::listen(JobAttempted::class, fn (JobAttempted $event) => $this->countAttempt(
            $event->job,
            match (true) {
                $event->job->hasFailed() => 'failed',
                $event->job->isReleased() => 'released',
                default => 'processed',
            },
        ));

        // On timeout the worker exits right after this event (stopping the worker flushes first), so
        // `JobAttempted` never fires. A timeout on the last try has already failed the job.
        Event::listen(JobTimedOut::class, fn (JobTimedOut $event) => $this->countAttempt(
            $event->job,
            $event->job->hasFailed() ? 'failed' : 'timed_out',
        ));
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
        $failer = app(FailedJobProviderInterface::class);
        $db = Spyglass::connection();
        $deleted = 0;

        $db->table(self::FAILED_JOBS)->select(['id', 'uuid'])->chunkById(500, function ($rows) use ($failer, $db, &$deleted) {
            $missing = $rows->filter(fn (object $row) => $failer->find($row->uuid) === null)->pluck('id');

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

        // The index mirrors the failer: without one there is nothing to retry or delete.
        if ($job instanceof SyncJob || $uuid === null || $failer instanceof NullFailedJobProvider) {
            return;
        }

        $snapshot = $this->attempts[$job] ?? Spyglass::captureContext();

        // `queue:retry` keeps the uuid, so a retried job that fails again updates its row.
        Spyglass::upsert(self::FAILED_JOBS, ['uuid' => $uuid], values: [
            'connection' => mb_substr($event->connectionName, 0, 64),
            'queue' => mb_substr($job->getQueue(), 0, 191),
            'job' => mb_substr($job->resolveName(), 0, 255),
            'attempts' => $job->attempts(),
            'exception_class' => mb_substr($event->exception::class, 0, 255),
            'exception_message' => mb_substr($event->exception->getMessage(), 0, 2000),
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
            $job->getQueue(),
            $job->resolveName(),
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
        $now = now();

        Spyglass::upsert(self::AGGREGATES, [
            // A string, so keys of the same bucket merge in the buffer.
            'bucket' => $now->setTimestamp(intdiv($now->getTimestamp(), 300) * 300)->toDateTimeString(),
            'connection' => mb_substr($connection, 0, 64),
            'queue' => mb_substr($queue, 0, 191),
            'job' => mb_substr($job, 0, 255),
            'scope' => config('spyglass.modules.queue.aggregate_by_scope') ? mb_substr($scope ?? '', 0, 191) : '',
        ], increment: [$counter => 1, ...($duration === null ? [] : ['duration_sum' => $duration])], max: $duration === null ? [] : ['duration_max' => $duration]);
    }
}
