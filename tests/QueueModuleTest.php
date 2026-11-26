<?php

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Keepsuit\Spyglass\Facades\Spyglass as SpyglassFacade;
use Keepsuit\Spyglass\Modules\Queue\QueueModule;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Tests\Fixtures\QueuedListener;
use Keepsuit\Spyglass\Tests\Fixtures\QueueJob;
use Keepsuit\Spyglass\Tests\Fixtures\WithQueueModule;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

uses(WithQueueModule::class);

enum QueueModuleTestQueue: string
{
    case Emails = 'emails';
}

function queueWork(int $tries = 1): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => $tries, '--sleep' => 0]);
}

/**
 * @return array<string, int>
 */
function queueCounters(): array
{
    $row = DB::table(QueueModule::AGGREGATES)
        ->selectRaw('sum(queued) as queued, sum(processed) as processed, sum(failed) as failed, sum(released) as released, sum(timed_out) as timed_out')
        ->first();

    return array_map(intval(...), (array) $row);
}

function queueFailedRows(): array
{
    return DB::table(QueueModule::FAILED_JOBS)->get()->map(fn ($row) => (array) $row)->all();
}

it('counts each attempt once', function (string $action, int $tries, int $runs, array $expected) {
    QueueJob::dispatch($action);
    SpyglassFacade::flush();

    for ($i = 0; $i < $runs; $i++) {
        queueWork($tries);
    }

    expect(queueCounters())->toBe(['queued' => 1, ...$expected]);
})->with([
    'success' => ['succeed', 1, 1, ['processed' => 1, 'failed' => 0, 'released' => 0, 'timed_out' => 0]],
    'exception on the last try' => ['throw', 1, 1, ['processed' => 0, 'failed' => 1, 'released' => 0, 'timed_out' => 0]],
    'exception, then exception on the last try' => ['throw', 2, 2, ['processed' => 0, 'failed' => 1, 'released' => 1, 'timed_out' => 0]],
    'manual fail' => ['fail', 3, 1, ['processed' => 0, 'failed' => 1, 'released' => 0, 'timed_out' => 0]],
    'manual release' => ['release', 3, 1, ['processed' => 0, 'failed' => 0, 'released' => 1, 'timed_out' => 0]],
    // Neither released nor failed by the worker, but the attempt did not succeed.
    'exception after deleting itself' => ['delete-throw', 3, 1, ['processed' => 0, 'failed' => 1, 'released' => 0, 'timed_out' => 0]],
]);

it('counts a job that already exceeded its tries as failed', function () {
    QueueJob::dispatch();
    SpyglassFacade::flush();
    // As left behind by a worker killed on timeout.
    DB::table('jobs')->update(['attempts' => 1]);

    queueWork(1);

    expect(queueCounters())->toBe(['queued' => 1, 'processed' => 0, 'failed' => 1, 'released' => 0, 'timed_out' => 0]);
});

it('stores a timed out attempt before the worker exits', function (int $tries, array $expected, int $failedRows) {
    QueueJob::dispatch('sleep');
    SpyglassFacade::flush();

    $stored = null;

    // The worker exits right after this event. Listening after the module and throwing keeps the test process alive.
    Event::listen(JobTimedOut::class, function () use (&$stored) {
        $stored = [queueCounters(), count(queueFailedRows())];
        app('queue.worker')->shouldQuit = true;

        throw new RuntimeException('Killed');
    });

    app('queue.worker')->daemon('database', 'default', new WorkerOptions(sleep: 0, maxTries: $tries, timeout: 1, stopWhenEmpty: true));

    expect($stored)->toBe([['queued' => 1, ...$expected], $failedRows])
        // Not counted again when the attempt unwinds.
        ->and(queueCounters())->toBe($stored[0]);
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

    expect(queueCounters())->toMatchArray(['queued' => 2, 'processed' => 0]);

    queueWork();

    expect(queueCounters())->toMatchArray(['queued' => 2, 'processed' => 1])
        ->and(DB::table(QueueModule::AGGREGATES)->get(['connection', 'queue', 'job'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['connection' => 'database', 'queue' => 'default', 'job' => QueueJob::class]]);
});

it('measures the duration of each attempt', function () {
    QueueJob::dispatch('pause');
    queueWork();

    $row = DB::table(QueueModule::AGGREGATES)->first();

    expect((int) $row->duration_sum)->toBeGreaterThanOrEqual(50)
        ->and((int) $row->duration_max)->toBe((int) $row->duration_sum);
});

it('writes queued and outcome counters of one flush in a single statement', function () {
    QueueJob::dispatch('dispatch');
    SpyglassFacade::flush();
    SpyglassFacade::connection()->enableQueryLog();

    queueWork();

    $upserts = collect(SpyglassFacade::connection()->getQueryLog())->pluck('query')
        ->filter(fn (string $sql) => str_starts_with(strtolower($sql), 'insert into') && str_contains($sql, QueueModule::AGGREGATES));

    expect($upserts)->toHaveCount(1)
        ->and(queueCounters())->toMatchArray(['queued' => 2, 'processed' => 1]);
});

it('keeps one series per queue when the worker reports the queue URL', function () {
    $url = 'https://sqs.eu-west-1.amazonaws.com/123456789012/emails';
    $payload = json_encode(['displayName' => QueueJob::class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => QueueJob::class]]);

    // `JobQueued` carries the name, an SQS worker the URL.
    event(new JobQueued('database', 'emails', 1, new QueueJob, $payload, null));
    Queue::connection('database')->push(new QueueJob, queue: $url);
    SpyglassFacade::flush();
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => $url, '--once' => true, '--sleep' => 0]);

    expect(DB::table(QueueModule::AGGREGATES)->get(['queue', 'queued', 'processed'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['queue' => 'emails', 'queued' => 2, 'processed' => 1]]);
});

it('names the queue as the app does when the driver stores it elsewhere', function () {
    Queue::forward('emails', 'emails-v2');

    QueueJob::dispatch('succeed')->onQueue('emails');
    QueueJob::dispatch('throw')->onQueue('emails');
    SpyglassFacade::flush();

    for ($i = 0; $i < 2; $i++) {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default,emails', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    }

    expect(DB::table(QueueModule::AGGREGATES)->get(['queue', 'queued', 'processed', 'failed'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['queue' => 'emails', 'queued' => 2, 'processed' => 1, 'failed' => 1]])
        ->and(queueFailedRows()[0]['queue'])->toBe('emails');
})->skip(fn () => ! method_exists(QueueManager::class, 'forward'), 'Queue forwards need Laravel 13.');

it('counts a queue given as an enum', function () {
    $payload = json_encode(['displayName' => QueueJob::class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => QueueJob::class]]);

    event(new JobQueued('database', QueueModuleTestQueue::Emails, 1, new QueueJob, $payload, null));
    SpyglassFacade::flush();

    expect(DB::table(QueueModule::AGGREGATES)->get(['queue', 'queued'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['queue' => 'emails', 'queued' => 1]]);
});

it('keeps one series per job class whatever its display name', function () {
    QueueJob::dispatch('succeed', 'Order #1');
    QueueJob::dispatch('throw', 'Order #2');
    SpyglassFacade::flush();
    queueWork();
    queueWork();

    expect(DB::table(QueueModule::AGGREGATES)->get(['job', 'queued', 'processed', 'failed'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['job' => QueueJob::class, 'queued' => 2, 'processed' => 1, 'failed' => 1]])
        ->and(queueFailedRows()[0]['job'])->toBe(QueueJob::class);
});

it('names framework-wrapped jobs after the class they wrap', function () {
    Event::listen('queue-module-test', QueuedListener::class);
    event('queue-module-test', [new stdClass]);
    SpyglassFacade::flush();
    queueWork();

    expect(DB::table(QueueModule::AGGREGATES)->get(['job', 'queued', 'processed'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['job' => QueuedListener::class, 'queued' => 1, 'processed' => 1]]);
});

it('never breaks the application or the failer when recording fails', function () {
    QueueJob::dispatch('throw');
    SpyglassFacade::flush();

    $broken = new class(SpyglassFacade::connection()->getName(), 'array') extends Spyglass
    {
        public function upsert(string $table, array $key, array $values = [], array $increment = [], array $max = [], array $min = []): void
        {
            throw new RuntimeException('Broken buffer');
        }
    };
    app()->instance(Spyglass::class, $broken);
    SpyglassFacade::clearResolvedInstance(Spyglass::class);

    QueueJob::dispatch();
    queueWork();

    expect(app('queue.failer')->ids())->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($this->spyglassErrors)->toHaveCount(1)
        ->and($this->spyglassErrors[0]->message)->toContain('Broken buffer');
    $this->spyglassErrors = [];
});

it('ignores sync jobs', function () {
    QueueJob::dispatchSync();
    rescue(fn () => QueueJob::dispatchSync('throw'), report: false);
    SpyglassFacade::flush();

    expect(DB::table(QueueModule::AGGREGATES)->count())->toBe(0)
        ->and(queueFailedRows())->toBeEmpty();
});

it('indexes a failed job', function () {
    QueueJob::dispatch('throw');
    queueWork();

    $uuid = app('queue.failer')->ids()[0];

    expect(queueFailedRows())->toHaveCount(1)
        ->and(queueFailedRows()[0])->toMatchArray([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'job' => QueueJob::class,
            'attempts' => 1,
            'exception_class' => RuntimeException::class,
            'scope' => null,
            'context' => null,
        ])
        ->and(queueFailedRows()[0]['fingerprint'])->toHaveLength(40)
        // The message may embed the payload, which stays in the failer.
        ->and(queueFailedRows()[0])->not->toHaveKey('exception_message');
});

it('indexes failed jobs only for failers keyed by uuid', function (string $driver) {
    config()->set('queue.failed.driver', $driver);
    app()->forgetInstance('queue.failer');

    QueueJob::dispatch('throw');
    queueWork();

    expect(queueCounters())->toMatchArray(['failed' => 1])
        ->and(queueFailedRows())->toBeEmpty();
})->with(['null', 'database']);

it('updates the index row when a retried job fails again', function () {
    QueueJob::dispatch('throw');
    queueWork();
    $uuid = app('queue.failer')->ids()[0];

    $this->travel(1)->hours();
    Artisan::call('queue:retry', ['id' => [$uuid]]);
    queueWork();

    expect(queueFailedRows())->toHaveCount(1)
        ->and(queueFailedRows()[0]['uuid'])->toBe($uuid)
        ->and(queueFailedRows()[0]['failed_at'])->toBe(now()->toDateTimeString());
});

it('keeps the scope captured when the job started after the context is cleared', function () {
    config()->set('spyglass.context.keys', ['tenant']);
    SpyglassFacade::resolveScopeUsing(fn (array $context) => isset($context['tenant']) ? "tenant:{$context['tenant']}" : null);

    Context::add('tenant', 'acme');
    QueueJob::dispatch('forget-context');
    Context::flush();

    queueWork();

    expect(queueFailedRows()[0]['scope'])->toBe('tenant:acme')
        ->and(json_decode(queueFailedRows()[0]['context'], true))->toBe(['tenant' => 'acme']);
});

it('keeps aggregates per scope next to the totals when enabled', function () {
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
    queueWork();
    queueWork();

    expect(DB::table(QueueModule::AGGREGATES)->orderBy('scope')->get(['scope', 'queued', 'processed'])->map(fn ($row) => (array) $row)->all())
        ->toBe([
            ['scope' => '', 'queued' => 2, 'processed' => 2],
            ['scope' => 'tenant:acme', 'queued' => 1, 'processed' => 1],
        ]);
});

it('counts a scope that clips to nothing in the total only, once', function () {
    config()->set('spyglass.modules.queue.aggregate_by_scope', true);
    SpyglassFacade::resolveScopeUsing(fn () => "\0");

    QueueJob::dispatch();
    SpyglassFacade::flush();

    expect(DB::table(QueueModule::AGGREGATES)->get(['scope', 'queued'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['scope' => '', 'queued' => 1]]);
});

it('adds up increments buffered by separate workers on the same bucket row', function () {
    QueueJob::dispatch();
    QueueJob::dispatch();
    SpyglassFacade::flush();

    // Each worker keeps its entries in its own buffer until both have run.
    $workers = [];

    foreach ([1, 2] as $i) {
        $workers[$i] = new Spyglass(SpyglassFacade::connection()->getName(), 'array');
        app()->instance(Spyglass::class, $workers[$i]);
        SpyglassFacade::clearResolvedInstance(Spyglass::class);

        queueWork();
    }

    $workers[2]->flush();
    $workers[1]->flush();

    expect(queueCounters())->toBe(['queued' => 2, 'processed' => 2, 'failed' => 0, 'released' => 0, 'timed_out' => 0]);
});

it('reads the failer ids once when pruning', function () {
    foreach (range(1, 3) as $i) {
        QueueJob::dispatch('throw');
        queueWork();
    }
    DB::connection()->enableQueryLog();

    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(collect(DB::connection()->getQueryLog())->pluck('query')->filter(fn (string $sql) => preg_match('/from "?`?failed_jobs/', $sql)))->toHaveCount(1)
        ->and(DB::table(QueueModule::FAILED_JOBS)->count())->toBe(3);
});

it('prunes old rows and failed jobs no longer in the failer', function () {
    QueueJob::dispatch('throw');
    QueueJob::dispatch('throw');
    queueWork();
    queueWork();
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

it('stores a timed out attempt when a real worker is killed', function (int $tries, array $expected, int $failedRows) {
    QueueJob::dispatch('sleep');
    SpyglassFacade::flush();

    // `--once` never arms the timeout, `--stop-when-empty` runs the daemon loop that does.
    $worker = new Process(
        [PHP_BINARY, __DIR__.'/Fixtures/worker.php', 'queue:work', 'database', '--stop-when-empty', '--timeout=1', "--tries={$tries}", '--sleep=0'],
        null,
        ['CACHE_STORE' => 'array', 'QUEUE_FAILED_DRIVER' => 'database-uuids', 'APP_KEY' => config('app.key')],
        timeout: 30,
    );

    // The worker kills itself with SIGKILL on timeout.
    expect(fn () => $worker->run())->toThrow(ProcessSignaledException::class)
        ->and(queueCounters())->toBe(['queued' => 1, ...$expected])
        ->and(queueFailedRows())->toHaveCount($failedRows);
})->with([
    'retried later' => [2, ['processed' => 0, 'failed' => 0, 'released' => 0, 'timed_out' => 1], 0],
    'last try' => [1, ['processed' => 0, 'failed' => 1, 'released' => 0, 'timed_out' => 0], 1],
])->skip(! extension_loaded('pcntl'), 'Requires pcntl')
    // The in-memory test database is not visible to another process.
    ->skip(fn () => DB::getDriverName() === 'sqlite', 'Requires a database server');
