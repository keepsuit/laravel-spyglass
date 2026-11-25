<?php

use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Keepsuit\Spyglass\Facades\Spyglass as SpyglassFacade;
use Keepsuit\Spyglass\Modules\Queue\QueueModule;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Tests\Fixtures\QueueJob;
use Keepsuit\Spyglass\Tests\Fixtures\WithQueueModule;

uses(WithQueueModule::class);

function work(int $tries = 1): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => $tries, '--sleep' => 0]);
}

/**
 * @return array<string, int>
 */
function counters(): array
{
    $row = DB::table(QueueModule::AGGREGATES)
        ->selectRaw('sum(queued) as queued, sum(processed) as processed, sum(failed) as failed, sum(released) as released, sum(timed_out) as timed_out')
        ->first();

    return array_map(intval(...), (array) $row);
}

function failedRows(): array
{
    return DB::table(QueueModule::FAILED_JOBS)->get()->map(fn ($row) => (array) $row)->all();
}

it('counts each attempt once', function (string $action, int $tries, int $runs, array $expected) {
    QueueJob::dispatch($action);
    SpyglassFacade::flush();

    for ($i = 0; $i < $runs; $i++) {
        work($tries);
    }

    expect(counters())->toBe(['queued' => 1, ...$expected]);
})->with([
    'success' => ['succeed', 1, 1, ['processed' => 1, 'failed' => 0, 'released' => 0, 'timed_out' => 0]],
    'exception on the last try' => ['throw', 1, 1, ['processed' => 0, 'failed' => 1, 'released' => 0, 'timed_out' => 0]],
    'exception, then exception on the last try' => ['throw', 2, 2, ['processed' => 0, 'failed' => 1, 'released' => 1, 'timed_out' => 0]],
    'manual fail' => ['fail', 3, 1, ['processed' => 0, 'failed' => 1, 'released' => 0, 'timed_out' => 0]],
    'manual release' => ['release', 3, 1, ['processed' => 0, 'failed' => 0, 'released' => 1, 'timed_out' => 0]],
]);

it('counts a job that already exceeded its tries as failed', function () {
    QueueJob::dispatch();
    SpyglassFacade::flush();
    // As left behind by a worker killed on timeout.
    DB::table('jobs')->update(['attempts' => 1]);

    work(1);

    expect(counters())->toBe(['queued' => 1, 'processed' => 0, 'failed' => 1, 'released' => 0, 'timed_out' => 0]);
});

it('stores a timed out attempt before the worker exits', function (int $tries, array $expected, int $failedRows) {
    QueueJob::dispatch('sleep');
    SpyglassFacade::flush();

    $stored = null;

    // The worker exits right after stopping. This listener runs after the flush and escapes before the exit.
    Event::listen(WorkerStopping::class, function () use (&$stored) {
        if ($stored === null) {
            $stored = [counters(), count(failedRows())];
            app('queue.worker')->shouldQuit = true;

            throw new RuntimeException('Killed');
        }
    });

    app('queue.worker')->daemon('database', 'default', new WorkerOptions(sleep: 0, maxTries: $tries, timeout: 1, stopWhenEmpty: true));

    expect($stored)->toBe([['queued' => 1, ...$expected], $failedRows])
        // Not counted again when the attempt unwinds.
        ->and(counters())->toBe($stored[0]);
})->with([
    'retried later' => [2, ['processed' => 0, 'failed' => 0, 'released' => 0, 'timed_out' => 1], 0],
    'last try' => [1, ['processed' => 0, 'failed' => 1, 'released' => 0, 'timed_out' => 0], 1],
])->skip(! extension_loaded('pcntl'), 'Requires pcntl');

it('counts queued jobs once per dispatch, on the queue they run on', function () {
    Route::get('/dispatch', function () {
        QueueJob::dispatch();
        QueueJob::dispatch();

        return 'ok';
    });

    $this->get('/dispatch')->assertOk();

    expect(counters())->toMatchArray(['queued' => 2, 'processed' => 0]);

    work();

    expect(counters())->toMatchArray(['queued' => 2, 'processed' => 1])
        ->and(DB::table(QueueModule::AGGREGATES)->get(['connection', 'queue', 'job'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['connection' => 'database', 'queue' => 'default', 'job' => QueueJob::class]]);
});

it('measures the duration of each attempt', function () {
    QueueJob::dispatch();
    work();

    $row = DB::table(QueueModule::AGGREGATES)->first();

    expect($row->duration_sum)->toBeGreaterThanOrEqual(0)
        ->and($row->duration_max)->toBe($row->duration_sum);
});

it('ignores sync jobs', function () {
    QueueJob::dispatchSync();
    rescue(fn () => QueueJob::dispatchSync('throw'), report: false);
    SpyglassFacade::flush();

    expect(DB::table(QueueModule::AGGREGATES)->count())->toBe(0)
        ->and(failedRows())->toBeEmpty();
});

it('indexes a failed job', function () {
    QueueJob::dispatch('throw');
    work();

    $uuid = app('queue.failer')->ids()[0];

    expect(failedRows())->toHaveCount(1)
        ->and(failedRows()[0])->toMatchArray([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'job' => QueueJob::class,
            'attempts' => 1,
            'exception_class' => RuntimeException::class,
            'exception_message' => 'Job failed',
            'scope' => null,
            'context' => null,
        ])
        ->and(failedRows()[0]['fingerprint'])->toHaveLength(40);
});

it('updates the index row when a retried job fails again', function () {
    QueueJob::dispatch('throw');
    work();
    $uuid = app('queue.failer')->ids()[0];

    $this->travel(1)->hours();
    Artisan::call('queue:retry', ['id' => [$uuid]]);
    work();

    expect(failedRows())->toHaveCount(1)
        ->and(failedRows()[0]['uuid'])->toBe($uuid)
        ->and(failedRows()[0]['failed_at'])->toBe(now()->toDateTimeString());
});

it('keeps the scope captured when the job started after the context is cleared', function () {
    config()->set('spyglass.context.keys', ['tenant']);
    SpyglassFacade::resolveScopeUsing(fn (array $context) => isset($context['tenant']) ? "tenant:{$context['tenant']}" : null);

    Context::add('tenant', 'acme');
    QueueJob::dispatch('forget-context');
    Context::flush();

    work();

    expect(failedRows()[0])->toMatchArray(['scope' => 'tenant:acme', 'context' => '{"tenant":"acme"}']);
});

it('splits aggregates by scope when enabled', function () {
    config()->set([
        'spyglass.modules.queue.aggregate_by_scope' => true,
        'spyglass.context.keys' => ['tenant'],
    ]);
    SpyglassFacade::resolveScopeUsing(fn (array $context) => isset($context['tenant']) ? "tenant:{$context['tenant']}" : null);

    Context::add('tenant', 'acme');
    QueueJob::dispatch();
    Context::forget('tenant');
    QueueJob::dispatch();
    SpyglassFacade::flush();
    work();
    work();

    expect(DB::table(QueueModule::AGGREGATES)->orderBy('scope')->get(['scope', 'queued', 'processed'])->map(fn ($row) => (array) $row)->all())
        ->toBe([
            ['scope' => '', 'queued' => 1, 'processed' => 1],
            ['scope' => 'tenant:acme', 'queued' => 1, 'processed' => 1],
        ]);
});

it('increments the same bucket from concurrent workers', function () {
    QueueJob::dispatch();
    QueueJob::dispatch();
    SpyglassFacade::flush();

    // Each worker keeps its entries in its own buffer until both have run.
    $workers = [];

    foreach ([1, 2] as $i) {
        $workers[$i] = new Spyglass(SpyglassFacade::connection()->getName(), 'array');
        app()->instance(Spyglass::class, $workers[$i]);
        SpyglassFacade::clearResolvedInstance(Spyglass::class);

        work();
    }

    $workers[2]->flush();
    $workers[1]->flush();

    expect(counters())->toBe(['queued' => 2, 'processed' => 2, 'failed' => 0, 'released' => 0, 'timed_out' => 0]);
});

it('prunes old rows and failed jobs no longer in the failer', function () {
    QueueJob::dispatch('throw');
    QueueJob::dispatch('throw');
    work();
    work();
    [$kept, $retried] = app('queue.failer')->ids();
    app('queue.failer')->forget($retried);

    DB::table(QueueModule::AGGREGATES)->insert(['bucket' => now()->subDays(8), 'connection' => 'database', 'queue' => 'default', 'job' => 'Old']);
    DB::table(QueueModule::FAILED_JOBS)->where('uuid', $kept)->update(['failed_at' => now()->subDays(29)]);

    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(DB::table(QueueModule::AGGREGATES)->pluck('job')->all())->toBe([QueueJob::class])
        ->and(DB::table(QueueModule::FAILED_JOBS)->pluck('uuid')->all())->toBe([$kept]);

    $this->travel(2)->days();
    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(DB::table(QueueModule::FAILED_JOBS)->count())->toBe(0);
});
