<?php

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Queue\Outcome;
use Keepsuit\Spyglass\Modules\Queue\QueueModule;
use Keepsuit\Spyglass\Modules\Queue\QueueQueries;
use Keepsuit\Spyglass\Tests\Fixtures\QueueJob;
use Keepsuit\Spyglass\Tests\Fixtures\WithQueueModule;

uses(WithQueueModule::class);

function aggregate(string $bucket, string $queue, string $job, array $counters = []): void
{
    DB::table(QueueModule::AGGREGATES)->insert(['bucket' => $bucket, 'connection' => 'database', 'queue' => $queue, 'job' => $job, ...$counters]);
}

function indexFailedJob(string $uuid, array $values = []): void
{
    DB::table(QueueModule::FAILED_JOBS)->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'job' => 'A', 'attempts' => 1,
        'exception_class' => 'E', 'exception_message' => 'm', 'fingerprint' => str_repeat('a', 40), 'failed_at' => now(),
        ...$values,
    ]);
}

function failJob(string $action = 'throw'): string
{
    QueueJob::dispatch($action);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);

    return app('queue.failer')->ids()[0];
}

function queries(): QueueQueries
{
    return app(QueueQueries::class);
}

it('returns counters per bucket grouped by queue or job', function () {
    aggregate('2026-01-01 10:00:00', 'default', 'A', ['processed' => 2, 'duration_sum' => 30, 'duration_max' => 20]);
    aggregate('2026-01-01 10:00:00', 'default', 'B', ['processed' => 1, 'failed' => 1, 'duration_sum' => 5, 'duration_max' => 5]);
    aggregate('2026-01-01 10:05:00', 'emails', 'A', ['queued' => 4]);
    aggregate('2026-01-01 11:00:00', 'default', 'A', ['processed' => 9]);

    $series = queries()->series(now()->parse('2026-01-01 10:00'), now()->parse('2026-01-01 10:55'), ['queue']);

    expect($series->map(fn ($row) => [$row['bucket'], $row['queue'], $row['processed'], $row['failed'], $row['queued'], $row['duration_sum'], $row['duration_max']])->all())->toBe([
        ['2026-01-01 10:00:00', 'default', 3, 1, 0, 35, 20],
        ['2026-01-01 10:05:00', 'emails', 0, 0, 4, 0, 0],
    ]);

    $totals = queries()->totals(now()->parse('2026-01-01 10:00'), now()->parse('2026-01-01 11:00'), ['job'], ['queue' => 'default']);

    expect($totals->map(fn ($row) => [$row['job'], $row['processed'], $row['failed']])->all())->toBe([
        ['A', 11, 0],
        ['B', 1, 1],
    ]);
});

it('paginates failed jobs with filters', function () {
    foreach (range(1, 3) as $i) {
        indexFailedJob("uuid-{$i}", ['queue' => $i === 3 ? 'emails' : 'default', 'scope' => 'tenant:acme', 'failed_at' => now()->addMinutes($i)]);
    }

    $page = queries()->failedJobs(['queue' => 'default', 'scope' => 'tenant:acme'], perPage: 1, page: 2);

    expect($page->total())->toBe(2)
        ->and(collect($page->items())->pluck('uuid')->all())->toBe(['uuid-1']);
});

it('bounds every query', function () {
    aggregate(now()->toDateTimeString(), 'default', 'A', ['processed' => 1]);
    indexFailedJob('uuid');
    Spyglass::connection()->enableQueryLog();

    queries()->series(now()->subHour(), now()->addHour(), ['queue', 'job']);
    queries()->totals(now()->subHour(), now()->addHour());
    queries()->failedJobs(['job' => 'A']);
    queries()->sizes(now()->subHour());

    $selects = collect(Spyglass::connection()->getQueryLog())->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, 'spyglass_'))
        // The paginator's total.
        ->reject(fn (string $sql) => str_contains($sql, 'count(*) as'));

    expect($selects)->toHaveCount(4)
        ->each->toContain('limit');
});

it('reads queue sizes and leaves them empty when the driver cannot report them', function () {
    config()->set('queue.connections.broken', ['driver' => 'missing']);
    aggregate(now()->toDateTimeString(), 'default', 'A');
    aggregate(now()->toDateTimeString(), 'default', 'B');
    DB::table(QueueModule::AGGREGATES)->insert(['bucket' => now()->toDateTimeString(), 'connection' => 'broken', 'queue' => 'q', 'job' => 'A']);
    Queue::connection('database')->later(60, new QueueJob);
    Queue::connection('database')->push(new QueueJob);

    expect(queries()->sizes(now()->subHour())->all())->toBe([
        ['connection' => 'broken', 'queue' => 'q', 'pending' => null, 'reserved' => null, 'delayed' => null],
        ['connection' => 'database', 'queue' => 'default', 'pending' => 1, 'reserved' => 0, 'delayed' => 1],
    ]);
});

it('returns the failer record of a failed job', function () {
    $uuid = failJob();

    expect(queries()->failedJob($uuid))->toHaveProperty('payload');
});

it('retries and deletes failed jobs through the failer', function (string $method) {
    $uuid = failJob();

    expect(queries()->{$method}($uuid))->toBe(Outcome::Done)
        ->and(app('queue.failer')->find($uuid))->toBeNull()
        ->and(DB::table(QueueModule::FAILED_JOBS)->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe($method === 'retry' ? 1 : 0);
})->with(['retry', 'forget']);

it('reports and removes failed jobs no longer in the failer', function (string $method) {
    $uuid = failJob();
    app('queue.failer')->forget($uuid);

    expect(queries()->{$method}($uuid))->toBe($method === 'failedJob' ? null : Outcome::Missing)
        ->and(DB::table(QueueModule::FAILED_JOBS)->count())->toBe(0);
})->with(['retry', 'forget', 'failedJob']);

it('reports a failure when Artisan fails', function (string $method) {
    $uuid = failJob();
    Artisan::registerCommand(new ClosureCommand("queue:{$method} {id*}", fn () => 1));

    expect(queries()->{$method}($uuid))->toBe(Outcome::Failed)
        ->and(DB::table(QueueModule::FAILED_JOBS)->count())->toBe(1);
})->with(['retry', 'forget']);
