<?php

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Queue\Console\RetryCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Queue\Outcome;
use Keepsuit\Spyglass\Modules\Queue\QueueModule;
use Keepsuit\Spyglass\Modules\Queue\QueueQueries;
use Keepsuit\Spyglass\Tests\Fixtures\QueueJob;
use Keepsuit\Spyglass\Tests\Fixtures\WithQueueModule;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

uses(WithQueueModule::class);

function queueAggregate(string $bucket, string $queue, string $job, array $counters = []): void
{
    DB::table(QueueModule::AGGREGATES)->insert(['bucket' => $bucket, 'connection' => 'database', 'queue' => $queue, 'job' => $job, ...$counters]);
}

function queueWorkOnce(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
}

function queueIndexFailedJob(string $uuid, array $values = []): void
{
    DB::table(QueueModule::FAILED_JOBS)->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'job' => 'A', 'attempts' => 1,
        'exception_class' => 'E', 'fingerprint' => str_repeat('a', 40), 'failed_at' => now(),
        ...$values,
    ]);
}

function queueFailJob(string $action = 'throw'): string
{
    QueueJob::dispatch($action);
    queueWorkOnce();

    return app('queue.failer')->ids()[0];
}

function queueQueries(): QueueQueries
{
    return app(QueueQueries::class);
}

it('returns counters per bucket grouped by queue or job', function () {
    queueAggregate('2026-01-01 10:00:00', 'default', 'A', ['processed' => 2, 'duration_sum' => 30, 'duration_max' => 20]);
    queueAggregate('2026-01-01 10:00:00', 'default', 'B', ['processed' => 1, 'failed' => 1, 'duration_sum' => 5, 'duration_max' => 5]);
    queueAggregate('2026-01-01 10:05:00', 'emails', 'A', ['queued' => 4]);
    queueAggregate('2026-01-01 11:00:00', 'default', 'A', ['processed' => 9]);

    $series = queueQueries()->series(now()->parse('2026-01-01 10:00'), now()->parse('2026-01-01 10:55'), ['queue']);

    expect($series->map(fn ($row) => [$row['bucket'], $row['queue'], $row['processed'], $row['failed'], $row['queued'], $row['duration_sum'], $row['duration_max']])->all())->toBe([
        ['2026-01-01 10:00:00', 'default', 3, 1, 0, 35, 20],
        ['2026-01-01 10:05:00', 'emails', 0, 0, 4, 0, 0],
    ]);

    $totals = queueQueries()->totals(now()->parse('2026-01-01 10:00'), now()->parse('2026-01-01 11:00'), ['job'], ['queue' => 'default']);

    expect($totals->map(fn ($row) => [$row['job'], $row['processed'], $row['failed']])->all())->toBe([
        ['A', 11, 0],
        ['B', 1, 1],
    ]);
});

it('keeps the newest whole buckets when the series exceeds its row limit', function () {
    $start = now()->parse('2026-01-01 00:00');
    // Three rows per bucket, so the row limit cuts the oldest bucket it reaches.
    $buckets = intdiv(QueueQueries::MAX_SERIES_ROWS, 3) + 1;
    $rows = [];

    foreach (range(0, $buckets - 1) as $i) {
        foreach (['A', 'B', 'C'] as $job) {
            $rows[] = ['bucket' => $start->copy()->addMinutes(5 * $i)->toDateTimeString(), 'connection' => 'database', 'queue' => 'default', 'job' => $job];
        }
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table(QueueModule::AGGREGATES)->insert($chunk);
    }

    $series = queueQueries()->series($start, $start->copy()->addYear(), ['job']);

    expect($series)->toHaveCount(3 * ($buckets - 1))
        ->and($series->first()['bucket'])->toBe($start->copy()->addMinutes(5)->toDateTimeString())
        ->and($series->last()['bucket'])->toBe($start->copy()->addMinutes(5 * ($buckets - 1))->toDateTimeString());
});

it('stores buckets in UTC and reads them back from any timezone', function () {
    $timezone = date_default_timezone_get();
    // Local buckets would repeat when DST ends. Carbon's test clock cannot hold that hour, so any local time shows the shift.
    date_default_timezone_set('Europe/Rome');

    try {
        $this->travelTo(now()->parse('2026-07-01 12:03:00'));
        QueueJob::dispatch();
        Spyglass::flush();

        expect(DB::table(QueueModule::AGGREGATES)->pluck('bucket')->map(fn ($bucket) => substr($bucket, 0, 19))->all())
            ->toBe(['2026-07-01 10:00:00'])
            ->and(queueQueries()->series(now()->parse('2026-07-01 11:59'), now()->parse('2026-07-01 12:01'))->pluck('queued')->all())
            ->toBe([1]);
    } finally {
        date_default_timezone_set($timezone);
    }
});

it('filters by connection, since queue names repeat across connections', function () {
    queueAggregate('2026-01-01 10:00:00', 'default', 'A', ['processed' => 1]);
    DB::table(QueueModule::AGGREGATES)->insert(['bucket' => '2026-01-01 10:00:00', 'connection' => 'redis', 'queue' => 'default', 'job' => 'A', 'processed' => 5]);
    queueIndexFailedJob('uuid-1');
    queueIndexFailedJob('uuid-2', ['connection' => 'redis']);

    $filters = ['connection' => 'database', 'queue' => 'default'];

    expect(queueQueries()->totals(now()->parse('2026-01-01 10:00'), now()->parse('2026-01-01 11:00'), ['queue'], $filters)->pluck('processed')->all())->toBe([1])
        ->and(collect(queueQueries()->failedJobs($filters)->items())->pluck('uuid')->all())->toBe(['uuid-1']);
});

it('clamps limits to at least one row', function () {
    queueAggregate('2026-01-01 10:00:00', 'default', 'A', ['processed' => 1]);
    queueAggregate('2026-01-01 10:00:00', 'emails', 'A', ['processed' => 2]);

    expect(queueQueries()->totals(now()->parse('2026-01-01 10:00'), now()->parse('2026-01-01 11:00'), limit: -1))->toHaveCount(1)
        ->and(queueQueries()->failedJobs(perPage: -1)->perPage())->toBe(1);
});

it('paginates failed jobs with filters', function () {
    foreach (range(1, 3) as $i) {
        queueIndexFailedJob("uuid-{$i}", ['queue' => $i === 3 ? 'emails' : 'default', 'scope' => 'tenant:acme', 'failed_at' => now()->addMinutes($i)]);
    }

    $page = queueQueries()->failedJobs(['queue' => 'default', 'scope' => 'tenant:acme'], perPage: 1, page: 2);

    expect($page->total())->toBe(2)
        ->and(collect($page->items())->pluck('uuid')->all())->toBe(['uuid-1']);
});

it('bounds every query', function () {
    queueAggregate(now()->toDateTimeString(), 'default', 'A', ['processed' => 1]);
    queueIndexFailedJob('uuid');
    Spyglass::connection()->enableQueryLog();

    queueQueries()->series(now()->subHour(), now()->addHour(), ['queue', 'job']);
    queueQueries()->totals(now()->subHour(), now()->addHour());
    queueQueries()->failedJobs(['job' => 'A']);
    queueQueries()->sizes(now()->subHour());

    $selects = collect(Spyglass::connection()->getQueryLog())->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, 'spyglass_'))
        // The paginator's total.
        ->reject(fn (string $sql) => str_contains($sql, 'count(*) as'));

    expect($selects)->toHaveCount(4)
        ->each->toContain('limit');
});

it('caches queue sizes for a few seconds', function () {
    queueAggregate(now()->toDateTimeString(), 'default', 'A');

    expect(queueQueries()->sizes(now()->subHour())->first()['pending'])->toBe(0);

    Queue::connection('database')->push(new QueueJob);

    expect(queueQueries()->sizes(now()->subHour())->first()['pending'])->toBe(0);

    $this->travel(10)->seconds();

    expect(queueQueries()->sizes(now()->subHour())->first()['pending'])->toBe(1);
});

it('reads queue sizes and leaves them empty when the driver cannot report them', function () {
    config()->set('queue.connections.broken', ['driver' => 'missing']);
    queueAggregate(now()->toDateTimeString(), 'default', 'A');
    queueAggregate(now()->toDateTimeString(), 'default', 'B');
    DB::table(QueueModule::AGGREGATES)->insert(['bucket' => now()->toDateTimeString(), 'connection' => 'broken', 'queue' => 'q', 'job' => 'A']);
    Queue::connection('database')->later(60, new QueueJob);
    Queue::connection('database')->push(new QueueJob);

    expect(queueQueries()->sizes(now()->subHour())->all())->toBe([
        ['connection' => 'broken', 'queue' => 'q', 'pending' => null, 'reserved' => null, 'delayed' => null],
        ['connection' => 'database', 'queue' => 'default', 'pending' => 1, 'reserved' => 0, 'delayed' => 1],
    ]);
});

it('returns the failer record of a failed job', function () {
    $uuid = queueFailJob();

    expect(queueQueries()->failedJob($uuid))->toHaveProperty('payload');
});

it('retries and deletes failed jobs through the failer', function (string $method, string $driver) {
    // The file failer matches ids strictly, so it rejects an id passed as a list where Laravel 12 expects one.
    File::ensureDirectoryExists($directory = sys_get_temp_dir().'/spyglass-'.getmypid());
    config()->set(['queue.failed.driver' => $driver, 'queue.failed.path' => "{$directory}/failed-jobs.json", 'cache.stores.file.path' => $directory]);
    app()->forgetInstance('queue.failer');

    $uuid = queueFailJob();

    expect(queueQueries()->{$method}($uuid))->toBe(Outcome::Done)
        ->and(app('queue.failer')->find($uuid))->toBeNull()
        ->and(DB::table(QueueModule::FAILED_JOBS)->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe($method === 'retry' ? 1 : 0);

    File::deleteDirectory($directory);
})->with(['retry', 'forget'])->with(['database-uuids', 'file']);

it('reports and removes failed jobs no longer in the failer', function (string $method) {
    $uuid = queueFailJob();
    app('queue.failer')->forget($uuid);

    expect(queueQueries()->{$method}($uuid))->toBe($method === 'failedJob' ? null : Outcome::Missing)
        ->and(DB::table(QueueModule::FAILED_JOBS)->count())->toBe(0);
})->with(['retry', 'forget', 'failedJob']);

it('keeps the index row of a retried job that fails again before the cleanup', function () {
    $uuid = queueFailJob();

    // The retried job runs and fails again before `queue:retry` returns.
    Artisan::registerCommand(new ClosureCommand('queue:retry {id*}', function () {
        $retry = app(RetryCommand::class);
        $retry->setLaravel(app());
        $retry->run(new ArrayInput(['id' => (array) $this->argument('id')]), new NullOutput);

        test()->travel(1)->minutes();
        queueWorkOnce();
    }));

    expect(queueQueries()->retry($uuid))->toBe(Outcome::Done)
        ->and(app('queue.failer')->find($uuid))->not->toBeNull()
        ->and(DB::table(QueueModule::FAILED_JOBS)->pluck('uuid')->all())->toBe([$uuid]);
});

it('reports a failure when the job is still in the failer after queue:forget', function () {
    $uuid = queueFailJob();
    Artisan::registerCommand(new ClosureCommand('queue:forget {id}', fn () => 0));

    expect(queueQueries()->forget($uuid))->toBe(Outcome::Failed)
        ->and(DB::table(QueueModule::FAILED_JOBS)->count())->toBe(1);
});

it('reports a failure when Artisan fails', function (string $method) {
    $uuid = queueFailJob();
    Artisan::registerCommand(new ClosureCommand("queue:{$method} {id*}", fn () => 1));

    expect(queueQueries()->{$method}($uuid))->toBe(Outcome::Failed)
        ->and(DB::table(QueueModule::FAILED_JOBS)->count())->toBe(1);
})->with(['retry', 'forget']);
