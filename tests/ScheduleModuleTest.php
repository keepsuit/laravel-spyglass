<?php

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\ScheduledTaskSkipReason;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Schedule\ScheduleModule;
use Keepsuit\Spyglass\Support\ExceptionFingerprint;
use Keepsuit\Spyglass\Tests\Fixtures\WithScheduleModule;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

uses(WithScheduleModule::class);

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));
    // Laravel fires `CommandStarting` outside unit tests only.
    app(ConsoleKernel::class)->rerouteSymfonyCommandEvents()->setArtisan(null);
});

function scheduleRuns(): Collection
{
    return DB::table(ScheduleModule::RUNS)->orderBy('started_at')->orderBy('id')->get();
}

function scheduleTask(Event $task): ?object
{
    return DB::table(ScheduleModule::TASKS)->where('key', taskKey($task))->first();
}

function taskKey(Event $task): string
{
    $tasks = array_values(app(Schedule::class)->events());

    return ScheduleModule::identities($tasks)[array_search($task, $tasks, true)]['key'];
}

/**
 * A `schedule:run` tick: what runs in `$during` happens between its start and its publication.
 */
function publishSchedule(?Closure $during = null): void
{
    event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));
    $during?->__invoke();
    event(new CommandFinished('schedule:run', new ArrayInput([]), new NullOutput, 0));
}

function outputFile(string $contents = ''): string
{
    $file = tempnam(sys_get_temp_dir(), 'spyglass');
    file_put_contents($file, $contents);

    return $file;
}

/**
 * Starts a `runInBackground` task as `schedule:run` does, without spawning it, and returns the
 * Context the background process would get.
 */
function startInBackground(Event $task): string
{
    event(new ScheduledTaskStarting($task));
    $context = json_encode(Context::dehydrate());
    event(new ScheduledTaskFinished($task, 0.0));

    return $context;
}

/**
 * Runs `schedule:finish` as the background process does: with the Context it was started with, if any,
 * restored by Laravel from the environment.
 */
function finishInBackground(Event $task, int $code, ?string $context = null): void
{
    Context::flush();

    if ($context !== null) {
        $_SERVER['__LARAVEL_CONTEXT'] = $context;
        app()->forgetInstance(ContextRepository::class);
        Facade::clearResolvedInstance(ContextRepository::class);
    }

    try {
        test()->artisan('schedule:finish', ['id' => $task->mutexName(), 'code' => $code])->assertSuccessful();
    } finally {
        unset($_SERVER['__LARAVEL_CONTEXT']);
    }
}

function failingMutex(): EventMutex
{
    return new class implements EventMutex
    {
        public function create(Event $event): bool
        {
            return false;
        }

        public function exists(Event $event): bool
        {
            return false;
        }

        public function forget(Event $event): void {}
    };
}

it('publishes the schedule on every run, keeping when each task was first seen', function () {
    $task = app(Schedule::class)->command('spyglass:test')->everyTenSeconds()->description('Test')->environments(['production']);
    app(Schedule::class)->call(fn () => null)->name('closure')->dailyAt('03:00')->timezone('Europe/Rome');

    $this->artisan('schedule:run');
    $this->travel(1)->minute();
    $this->artisan('schedule:run');

    expect((array) scheduleTask($task))->toMatchArray([
        'label' => 'php artisan spyglass:test',
        'command' => 'php artisan spyglass:test',
        'description' => 'Test',
        'expression' => '* * * * *',
        'repeat_seconds' => 10,
        'timezone' => 'UTC',
        'environments' => '["production"]',
        'last_skipped_at' => null,
    ])
        ->and((bool) scheduleTask($task)->without_overlapping)->toBeFalse()
        ->and(substr(scheduleTask($task)->first_seen_at, 11, 5))->toBe('12:00')
        ->and(substr(scheduleTask($task)->last_seen_at, 11, 5))->toBe('12:01')
        ->and(DB::table(ScheduleModule::TASKS)->where('label', 'closure')->value('timezone'))->toBe('Europe/Rome');
});

it('publishes whether a task prevents overlaps', function () {
    $task = app(Schedule::class)->exec('true')->everyMinute()->withoutOverlapping();

    publishSchedule();

    expect((bool) scheduleTask($task)->without_overlapping)->toBeTrue();
});

it('publishes long cron expressions in full', function () {
    $task = app(Schedule::class)->exec('true')->cron($expression = implode(',', range(0, 59)).' * * * *');

    publishSchedule();

    expect(scheduleTask($task)->expression)->toBe($expression);
});

it('publishes the schedule in a single statement', function () {
    foreach (range(1, 5) as $i) {
        app(Schedule::class)->command("spyglass:test-{$i}")->daily();
    }

    $queries = [];
    Spyglass::connection()->listen(function (QueryExecuted $query) use (&$queries) {
        $queries[] = $query->sql;
    });

    publishSchedule();

    // And the prune command Spyglass schedules.
    expect($queries)->toHaveCount(1)
        ->and(DB::table(ScheduleModule::TASKS)->count())->toBe(6);
});

it('publishes the schedule after the due tasks ran, as seen when the tick started', function () {
    $this->travelTo(now()->setTime(23, 59));
    $task = app(Schedule::class)->exec('true')->dailyAt('23:59');

    // A publication slow enough to cross midnight.
    Spyglass::connection()->listen(function (QueryExecuted $query) {
        if (str_contains($query->sql, ScheduleModule::TASKS)) {
            $this->travel(2)->minutes();
        }
    });

    $this->artisan('schedule:run');

    expect(scheduleRuns()->pluck('task')->all())->toBe([taskKey($task)])
        ->and(substr(scheduleTask($task)->last_seen_at, 11, 5))->toBe('23:59');
});

it('runs the due tasks when the publication fails', function () {
    app(Schedule::class)->exec('true')->everyMinute();
    Spyglass::connection()->beforeExecuting(function (string $sql) {
        // Only the publication: the callback outlives the test, into the rollback of the migrations.
        if (str_starts_with(strtolower($sql), 'insert') && str_contains($sql, ScheduleModule::TASKS)) {
            throw new RuntimeException('Database down');
        }
    });

    $this->artisan('schedule:run');

    expect(scheduleRuns()->pluck('status')->all())->toBe(['success'])
        ->and($this->spyglassErrors[0]->message)->toStartWith('Spyglass failed to publish the schedule');
    $this->spyglassErrors = [];
});

it('records a successful run', function () {
    $task = app(Schedule::class)->exec('true')->everyMinute();

    $this->artisan('schedule:run');

    expect(scheduleRuns())->toHaveCount(1)
        ->and((array) scheduleRuns()->first())->toMatchArray([
            'task' => taskKey($task),
            'status' => 'success',
            'exit_code' => 0,
            'output' => null,
            'host' => gethostname(),
        ])
        ->and(scheduleRuns()->first()->finished_at)->not->toBeNull()
        ->and(scheduleRuns()->first()->duration_ms)->toBeInt();
});

it('stores datetimes in UTC whatever the application timezone', function () {
    $timezone = date_default_timezone_get();
    date_default_timezone_set('America/New_York');

    try {
        $task = app(Schedule::class)->call(fn () => null)->everyMinute();
        $this->travelTo(Carbon::parse('2026-01-01 12:00:00', 'UTC'));

        $this->artisan('schedule:run');

        expect(scheduleRuns()->first()->started_at)->toStartWith('2026-01-01 12:00:00')
            ->and(scheduleRuns()->first()->finished_at)->toStartWith('2026-01-01 12:00:00')
            ->and(scheduleTask($task)->last_seen_at)->toStartWith('2026-01-01 12:00:00');
    } finally {
        date_default_timezone_set($timezone);
    }
});

it('stores the running run before the task executes', function () {
    app(Schedule::class)->call(fn () => expect(scheduleRuns()->pluck('status')->all())->toBe(['running']))->everyMinute();

    $this->artisan('schedule:run');

    expect(scheduleRuns()->pluck('status')->all())->toBe(['success']);
});

it('records a non-zero exit code as one failed run', function () {
    app(Schedule::class)->exec('exit 3')->everyMinute();

    $this->artisan('schedule:run');

    expect(scheduleRuns())->toHaveCount(1)
        ->and(scheduleRuns()->first()->status)->toBe('failed')
        ->and(scheduleRuns()->first()->exit_code)->toBe(3)
        // Laravel's own exception for an exit code says nothing about the cause.
        ->and(scheduleRuns()->first()->exception_class)->toBeNull()
        ->and(scheduleRuns()->first()->fingerprint)->toBeNull();
});

it('records a throwing closure as a failed run', function () {
    $exception = new RuntimeException('boom');
    app(Schedule::class)->call(fn () => throw $exception)->everyMinute();

    $this->artisan('schedule:run');

    expect(scheduleRuns())->toHaveCount(1)
        ->and(scheduleRuns()->first()->status)->toBe('failed')
        ->and(scheduleRuns()->first()->exit_code)->toBe(1)
        ->and(scheduleRuns()->first()->exception_class)->toBe(RuntimeException::class)
        ->and(scheduleRuns()->first()->fingerprint)->toBe(ExceptionFingerprint::for($exception));
});

it('records a run skipped because it overlaps as a skip of the task, not as a run', function (bool $background) {
    if ($background && ! property_exists(Event::class, 'skippedBecauseOverlapping')) {
        $this->markTestSkipped('Without the overlap flag a skipped background run is indistinguishable from a started one.');
    }

    // Another process takes the mutex between the filter check and the run.
    $task = app(Schedule::class)->exec('true')->everyMinute();
    $task->runInBackground = $background;
    $task->withoutOverlapping()->preventOverlapsUsing(failingMutex());

    $this->artisan('schedule:run');

    expect(scheduleRuns())->toHaveCount(0)
        ->and(scheduleTask($task)->last_skip_reason)->toBe('overlapping')
        ->and(scheduleTask($task)->last_skipped_at)->not->toBeNull();
})->with(['foreground' => false, 'background' => true]);

it('keeps an overlap skip of a sub-minute task after a failed execution', function () {
    // Laravel reuses the task object: the skip keeps the exit code of the failed execution and fires `ScheduledTaskFailed`.
    $task = app(Schedule::class)->exec('exit 1')->everyTenSeconds();
    publishSchedule();

    event(new ScheduledTaskStarting($task));
    $task->exitCode = 1;
    event(new ScheduledTaskFinished($task, 0.0));
    event(new ScheduledTaskFailed($task, new RuntimeException('failed'), 0.0));

    $this->travel(10)->seconds();
    event(new ScheduledTaskStarting($task));
    $task->skippedBecauseOverlapping = true;
    event(new ScheduledTaskFinished($task, 0.0));
    event(new ScheduledTaskFailed($task, new RuntimeException('failed'), 0.0));

    expect(scheduleRuns()->pluck('status')->all())->toBe(['failed'])
        ->and(scheduleTask($task)->last_skip_reason)->toBe('overlapping');
})->skip(! property_exists(Event::class, 'skippedBecauseOverlapping'), 'Requires the overlap flag');

it('keeps only the last successful run of a sub-minute task, on the task row', function () {
    $task = app(Schedule::class)->call(fn () => null)->name('poll')->everyTenSeconds();
    publishSchedule();

    $writes = 0;
    Spyglass::connection()->listen(function (QueryExecuted $query) use (&$writes) {
        $writes += str_starts_with(strtolower($query->sql), 'update') ? 1 : 0;
    });

    publishSchedule(function () use ($task) {
        foreach (range(0, 50, 10) as $second) {
            $this->travelTo(now()->setTime(12, 1, $second));
            event(new ScheduledTaskStarting($task));
            $task->exitCode = 0;
            event(new ScheduledTaskFinished($task, 0.0));
        }
    });

    expect(scheduleRuns())->toHaveCount(0)
        ->and(substr(scheduleTask($task)->last_run_at, 11, 8))->toBe('12:01:50')
        ->and(scheduleTask($task)->last_duration_ms)->not->toBeNull()
        // At 12:01:00 and 12:01:30; the publication writes the last one.
        ->and($writes)->toBe(2);
});

it('records every failed run of a sub-minute task', function () {
    $task = app(Schedule::class)->call(fn () => throw new RuntimeException('boom'))->name('poll')->everyTenSeconds();
    publishSchedule();

    event(new ScheduledTaskStarting($task));
    $task->exitCode = 1;
    event(new ScheduledTaskFailed($task, new RuntimeException('boom'), 0.0));

    expect(scheduleRuns()->pluck('status')->all())->toBe(['failed'])
        ->and(scheduleTask($task)->last_run_at)->toBeNull();
});

it('records a task skipped by its filters on the task row', function () {
    $task = app(Schedule::class)->call(fn () => null)->name('filtered')->everyMinute()->when(false);

    $this->artisan('schedule:run');

    expect(scheduleRuns())->toHaveCount(0)
        ->and(scheduleTask($task)->last_skipped_at)->not->toBeNull()
        ->and(scheduleTask($task)->last_skip_reason)->toBe(enum_exists(ScheduledTaskSkipReason::class) ? 'condition_not_met' : null);
});

it('records why a task was skipped', function () {
    $task = app(Schedule::class)->exec('true')->everyMinute();
    publishSchedule();

    event(new ScheduledTaskSkipped($task, ScheduledTaskSkipReason::RanOnAnotherServer));

    expect(scheduleTask($task)->last_skip_reason)->toBe('ran_on_another_server');
})->skip(! enum_exists(ScheduledTaskSkipReason::class), 'Requires skip reasons');

it('records an overlap caught by the filters as overlapping', function () {
    $task = app(Schedule::class)->exec('true')->everyMinute()->withoutOverlapping();
    $task->mutex->create($task);

    $this->artisan('schedule:run');

    expect(scheduleRuns())->toHaveCount(0)
        ->and(scheduleTask($task)->last_skip_reason)->toBe('overlapping');
});

it('writes repeated skips of a sub-minute task at most every 30 seconds', function () {
    $task = app(Schedule::class)->call(fn () => null)->name('filtered')->everySecond()->when(false);
    publishSchedule();

    $writes = 0;
    Spyglass::connection()->listen(function (QueryExecuted $query) use (&$writes) {
        $writes += str_starts_with(strtolower($query->sql), 'update') ? 1 : 0;
    });

    foreach (range(1, 59) as $second) {
        event(new ScheduledTaskSkipped($task));
        $this->travel(1)->second();
    }

    expect($writes)->toBe(2);
});

it('records the last skip of the tick, however recent the previous write', function () {
    $task = app(Schedule::class)->call(fn () => null)->name('filtered')->everyTenSeconds()->when(false);

    publishSchedule(function () use ($task) {
        foreach (range(0, 50, 10) as $second) {
            $this->travelTo(now()->setTime(12, 0, $second));
            event(new ScheduledTaskSkipped($task));
        }
    });

    expect(substr(scheduleTask($task)->last_skipped_at, 11, 8))->toBe('12:00:50');
});

it('writes the next skip when the previous write failed', function () {
    $task = app(Schedule::class)->call(fn () => null)->name('filtered')->everyTenSeconds()->when(false);
    publishSchedule();

    $failed = false;
    Spyglass::connection()->beforeExecuting(function (string $sql) use (&$failed) {
        if (! $failed && str_starts_with(strtolower($sql), 'update')) {
            $failed = true;
            throw new RuntimeException('Database down');
        }
    });

    event(new ScheduledTaskSkipped($task));
    $this->travel(10)->seconds();
    event(new ScheduledTaskSkipped($task));

    expect(substr(scheduleTask($task)->last_skipped_at, 11, 8))->toBe('12:00:10');
    $this->spyglassErrors = [];
});

it('records due tasks left out while the application is down for maintenance', function () {
    $down = app(Schedule::class)->exec('true')->everyMinute();
    $notDue = app(Schedule::class)->exec('false')->dailyAt('03:00');
    $evenInMaintenance = app(Schedule::class)->exec('printf ok')->everyMinute()->evenInMaintenanceMode();
    $this->app->maintenanceMode()->activate([]);

    try {
        $this->artisan('schedule:run');
    } finally {
        $this->app->maintenanceMode()->deactivate();
    }

    expect(scheduleTask($down)->last_skip_reason)->toBe('maintenance')
        ->and(scheduleTask($notDue)->last_skip_reason)->toBeNull()
        ->and(scheduleTask($evenInMaintenance)->last_skip_reason)->toBeNull()
        ->and(scheduleRuns()->pluck('task')->all())->toBe([taskKey($evenInMaintenance)]);
});

it('keeps a background task running until schedule:finish reports its real outcome and duration', function (int $code, string $status) {
    $task = app(Schedule::class)->exec("exit {$code}")->runInBackground()->everyMinute();

    $context = startInBackground($task);

    expect(scheduleRuns()->pluck('status')->all())->toBe(['running']);

    $this->travel(5)->seconds();
    finishInBackground($task, $code, $context);

    expect(scheduleRuns())->toHaveCount(1)
        ->and(scheduleRuns()->first()->status)->toBe($status)
        ->and(scheduleRuns()->first()->exit_code)->toBe($code)
        ->and(scheduleRuns()->first()->duration_ms)->toBe(5000);
})->with([
    'success' => [0, 'success'],
    'failure' => [3, 'failed'],
]);

it('closes each overlapping background run with its own outcome', function (bool $firstFinishesFirst) {
    $task = app(Schedule::class)->exec('true')->runInBackground()->everyMinute();

    $first = startInBackground($task);
    $this->travel(1)->minute();
    $second = startInBackground($task);
    $this->travel(5)->seconds();

    finishInBackground($task, $firstFinishesFirst ? 0 : 3, $firstFinishesFirst ? $first : $second);
    $this->travel(5)->seconds();
    finishInBackground($task, $firstFinishesFirst ? 3 : 0, $firstFinishesFirst ? $second : $first);

    expect(scheduleRuns()->map(fn ($run) => [$run->status, $run->duration_ms])->all())
        ->toBe($firstFinishesFirst ? [['success', 65000], ['failed', 10000]] : [['success', 70000], ['failed', 5000]]);
})->with([
    'first started finishes first' => true,
    'last started finishes first' => false,
]);

it('passes the run id to the background process', function () {
    $file = outputFile();
    app(Schedule::class)->exec('printenv __LARAVEL_CONTEXT')->runInBackground()->everyMinute()->sendOutputTo($file);

    $this->artisan('schedule:run');

    $deadline = microtime(true) + 10;
    while (trim((string) file_get_contents($file)) === '' && microtime(true) < $deadline) {
        usleep(20_000);
    }

    $context = json_decode(trim((string) file_get_contents($file)), true);

    expect(unserialize($context['hidden'][ScheduleModule::CONTEXT_RUN] ?? 'N;'))->toBe(scheduleRuns()->first()->id)
        ->and(Context::hasHidden(ScheduleModule::CONTEXT_RUN))->toBeFalse();
});

it('closes a background run once when identical tasks share a mutex', function () {
    $first = app(Schedule::class)->exec('true')->runInBackground()->everyMinute();
    $second = app(Schedule::class)->exec('true')->runInBackground()->everyMinute();

    $context = startInBackground($first);
    startInBackground($second);

    // `schedule:finish` reports the completion to every task with the mutex.
    finishInBackground($first, 0, $context);

    expect(scheduleRuns()->pluck('status', 'task')->all())->toBe([taskKey($first) => 'success', taskKey($second) => 'running']);
});

it('looks up the background run on the write connection', function () {
    $task = app(Schedule::class)->exec('true')->runInBackground()->everyMinute();
    $context = startInBackground($task);

    // A replica that has not received the start of the run yet.
    $replica = new PDO('sqlite::memory:');
    $replica->exec('create table '.ScheduleModule::RUNS.' (id, task, host, status, started_at, output_offset)');
    Spyglass::connection()->setReadPdo($replica);

    try {
        finishInBackground($task, 0, $context);
    } finally {
        Spyglass::connection()->setReadPdo(null);
    }

    expect(scheduleRuns()->pluck('status')->all())->toBe(['success']);
});

it('stores the output only when the task writes it', function () {
    $file = outputFile();
    $hello = app(Schedule::class)->exec('printf hello')->everyMinute()->sendOutputTo($file);
    $ignored = app(Schedule::class)->exec('printf ignored')->everyMinute();

    $this->artisan('schedule:run');

    expect(scheduleRuns()->pluck('output', 'task')->all())->toBe([taskKey($hello) => 'hello', taskKey($ignored) => null]);
});

it('stores only the output appended by the run', function () {
    $file = outputFile('previous runs');
    app(Schedule::class)->exec('printf done.')->everyMinute()->appendOutputTo($file);

    $this->artisan('schedule:run');

    expect(scheduleRuns()->first()->output)->toBe('done.');
});

it('records the run without output when the output size cannot be read', function () {
    $file = outputFile('previous runs');
    $task = app(Schedule::class)->exec('true')->everyMinute()->appendOutputTo($file);

    // Makes the size lookup throw, as a rotation between the existence check and the size read does.
    $task->output = "{$file}\0";
    event(new ScheduledTaskStarting($task));
    $task->output = $file;

    file_put_contents($file, 'done.', FILE_APPEND);
    $task->exitCode = 0;
    event(new ScheduledTaskFinished($task, 0.0));

    expect(scheduleRuns()->map(fn ($run) => [$run->status, $run->output])->all())->toBe([['success', null]])
        ->and($this->spyglassErrors[0]->message)->toStartWith('Spyglass failed to read the output size of a scheduled task');
    $this->spyglassErrors = [];
});

it('stores the output appended by a background run', function () {
    $file = outputFile('previous runs');
    $task = app(Schedule::class)->exec('true')->runInBackground()->everyMinute()->appendOutputTo($file);

    $context = startInBackground($task);
    file_put_contents($file, 'done.', FILE_APPEND);
    finishInBackground($task, 0, $context);

    expect(scheduleRuns()->first()->output)->toBe('done.');
});

it('reads a relative output path from the base path, as the task writes it', function () {
    $file = 'spyglass-'.Str::random().'.log';
    app(Schedule::class)->exec('printf hello')->everyMinute()->sendOutputTo($file);

    try {
        $this->artisan('schedule:run');
    } finally {
        @unlink(base_path($file));
    }

    expect(scheduleRuns()->first()->output)->toBe('hello');
});

it('stores the tail of a long output as valid UTF-8', function () {
    $file = outputFile();
    app(Schedule::class)->exec('printf '.str_repeat('é', 20000).'done.')->everyMinute()->sendOutputTo($file);

    $this->artisan('schedule:run');

    $output = scheduleRuns()->first()->output;

    expect(strlen($output))->toBeLessThanOrEqual(ScheduleModule::OUTPUT_BYTES)
        ->and(strlen($output))->toBeGreaterThan(ScheduleModule::OUTPUT_BYTES - 4)
        ->and($output)->toEndWith('done.')
        ->and(mb_check_encoding($output, 'UTF-8'))->toBeTrue();
});

it('keys a task by its command or description, not by its cron expression', function () {
    $hourly = (new Schedule)->command('spyglass:test --flag')->hourly();
    $daily = (new Schedule)->command('spyglass:test --flag')->daily();
    $named = (new Schedule)->job('App\Jobs\Report')->daily();

    expect(ScheduleModule::identities([$hourly]))->toBe([['key' => sha1('1:php artisan spyglass:test --flag'), 'label' => 'php artisan spyglass:test --flag']])
        ->and(ScheduleModule::identities([$daily]))->toBe(ScheduleModule::identities([$hourly]))
        ->and(ScheduleModule::identities([$named])[0]['label'])->toBe('App\Jobs\Report');
});

it('labels a closure by its file and line relative to the base path', function () {
    $this->app->setBasePath(dirname(__DIR__));

    $closure = (new Schedule)->call(fn () => null);
    $line = __LINE__ - 1;
    $named = (new Schedule)->call(fn () => null)->name('named');

    expect(array_column(ScheduleModule::identities([$closure, $named]), 'label'))->toBe(['tests/ScheduleModuleTest.php:'.$line, 'named']);
});

it('keys duplicates by position, apart from tasks whose name looks like a duplicate', function () {
    $schedule = new Schedule;
    $schedule->command('spyglass:test')->hourly();
    $schedule->call(fn () => null)->name('php artisan spyglass:test #2');
    $schedule->command('spyglass:test')->daily();

    $identities = ScheduleModule::identities($schedule->events());

    expect(array_column($identities, 'label'))->toBe(['php artisan spyglass:test', 'php artisan spyglass:test #2', 'php artisan spyglass:test #2'])
        ->and(array_unique(array_column($identities, 'key')))->toHaveCount(3);
});

it('keys long identities in full', function () {
    $prefix = str_repeat('a', 300);

    $identities = ScheduleModule::identities([(new Schedule)->exec("{$prefix} 1"), (new Schedule)->exec("{$prefix} 2")]);

    expect($identities[1]['key'])->toBe(sha1("1:{$prefix} 2"))
        ->and(mb_strlen($identities[0]['label']))->toBeLessThanOrEqual(255);
});

it('records runs under the keys published with the schedule', function () {
    $first = app(Schedule::class)->exec('true')->everyMinute();
    $second = app(Schedule::class)->exec('true')->everyMinute();

    $this->artisan('schedule:run');

    expect(scheduleRuns()->pluck('task')->sort()->values()->all())->toBe(collect([taskKey($first), taskKey($second)])->sort()->values()->all())
        ->and(scheduleTask($first)->label)->toBe('true')
        ->and(scheduleTask($second)->label)->toBe('true #2');
});

it('prunes runs and tasks not seen within the retention', function () {
    app(Schedule::class)->exec('false')->everyMinute();
    $this->artisan('schedule:run');
    $this->travel(31)->days();
    (fn () => $this->events = [])->call(app(Schedule::class));
    $kept = app(Schedule::class)->exec('true')->everyMinute();
    $this->artisan('schedule:run');

    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(scheduleRuns())->toHaveCount(1)
        ->and(scheduleRuns()->first()->started_at)->toStartWith(now('UTC')->toDateString())
        ->and(scheduleTask($kept))->not->toBeNull()
        ->and(DB::table(ScheduleModule::TASKS)->count())->toBe(1);
});
