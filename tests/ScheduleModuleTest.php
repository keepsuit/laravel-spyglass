<?php

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Modules\Schedule\ScheduleModule;
use Keepsuit\Spyglass\Tests\Fixtures\WithScheduleModule;

uses(WithScheduleModule::class);

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));
});

function scheduleRuns(): Collection
{
    return DB::table(ScheduleModule::RUNS)->orderBy('started_at')->orderBy('id')->get();
}

function outputFile(string $contents = ''): string
{
    $file = tempnam(sys_get_temp_dir(), 'spyglass');
    file_put_contents($file, $contents);

    return $file;
}

/**
 * Starts a `runInBackground` task as `schedule:run` does, without spawning it.
 */
function startInBackground(Event $task): void
{
    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.0));
}

it('records a successful run', function () {
    app(Schedule::class)->exec('true')->everyMinute();

    $this->artisan('schedule:run');

    expect(scheduleRuns())->toHaveCount(1)
        ->and((array) scheduleRuns()->first())->toMatchArray([
            'task' => 'true',
            'command' => 'true',
            'expression' => '* * * * *',
            'status' => 'success',
            'exit_code' => 0,
            'output' => null,
            'host' => gethostname(),
        ])
        ->and(scheduleRuns()->first()->finished_at)->not->toBeNull()
        ->and(scheduleRuns()->first()->duration_ms)->toBeInt();
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
        ->and(scheduleRuns()->first()->exit_code)->toBe(3);
});

it('records a throwing closure as a failed run', function () {
    app(Schedule::class)->call(fn () => throw new RuntimeException('boom'))->everyMinute();

    $this->artisan('schedule:run');

    expect(scheduleRuns())->toHaveCount(1)
        ->and(scheduleRuns()->first()->status)->toBe('failed')
        ->and(scheduleRuns()->first()->exit_code)->toBe(1);
});

it('records a run skipped because it overlaps as skipped, not as a success', function (bool $background) {
    // Another process takes the mutex between the filter check and the run.
    $task = app(Schedule::class)->exec('true')->everyMinute();
    $task->runInBackground = $background;
    $task->withoutOverlapping()->preventOverlapsUsing(new class implements EventMutex
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
    });

    $this->artisan('schedule:run');

    expect(scheduleRuns())->toHaveCount(1)
        ->and(scheduleRuns()->first()->status)->toBe('skipped')
        ->and(scheduleRuns()->first()->exit_code)->toBeNull();
})->with(['foreground' => false, 'background' => true]);

it('records a task skipped by its filters', function () {
    app(Schedule::class)->call(fn () => null)->name('filtered')->everyMinute()->when(false);

    $this->artisan('schedule:run');

    expect(scheduleRuns())->toHaveCount(1)
        ->and(scheduleRuns()->first()->task)->toBe('filtered')
        ->and(scheduleRuns()->first()->status)->toBe('skipped');
});

it('keeps a background task running until schedule:finish reports its real outcome and duration', function (int $code, string $status) {
    $task = app(Schedule::class)->exec("exit {$code}")->runInBackground()->everyMinute();

    startInBackground($task);

    expect(scheduleRuns()->pluck('status')->all())->toBe(['running']);

    $this->travel(5)->seconds();
    $this->artisan('schedule:finish', ['id' => $task->mutexName(), 'code' => $code])->assertSuccessful();

    expect(scheduleRuns())->toHaveCount(1)
        ->and(scheduleRuns()->first()->status)->toBe($status)
        ->and(scheduleRuns()->first()->exit_code)->toBe($code)
        ->and(scheduleRuns()->first()->duration_ms)->toBe(5000);
})->with([
    'success' => [0, 'success'],
    'failure' => [3, 'failed'],
]);

it('closes a background run only on the host that started it', function () {
    $task = app(Schedule::class)->exec('true')->runInBackground()->everyMinute();

    startInBackground($task);

    $this->travel(1)->seconds();
    DB::table(ScheduleModule::RUNS)->insert([
        'id' => (string) Str::ulid(),
        'task' => 'true',
        'expression' => '* * * * *',
        'started_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'running',
        'host' => 'another-host',
    ]);

    $this->artisan('schedule:finish', ['id' => $task->mutexName(), 'code' => 0]);

    expect(scheduleRuns()->pluck('status', 'host')->all())->toBe([gethostname() => 'success', 'another-host' => 'running']);
});

it('stores the output only when the task writes it', function () {
    $file = outputFile();
    app(Schedule::class)->exec('printf hello')->everyMinute()->sendOutputTo($file);
    app(Schedule::class)->exec('printf ignored')->everyMinute();

    $this->artisan('schedule:run');

    expect(scheduleRuns()->pluck('output', 'task')->all())->toBe(['printf hello' => 'hello', 'printf ignored' => null]);
});

it('stores the tail of the output as valid UTF-8', function () {
    $file = outputFile(str_repeat('é', 20000));
    app(Schedule::class)->exec('printf done.')->everyMinute()->appendOutputTo($file);

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

    expect(ScheduleModule::keys([$hourly]))->toBe(['php artisan spyglass:test --flag'])
        ->and(ScheduleModule::keys([$daily]))->toBe(ScheduleModule::keys([$hourly]))
        ->and(ScheduleModule::keys([$named]))->toBe(['App\Jobs\Report']);
});

it('keys a closure by its file and line relative to the base path', function () {
    $this->app->setBasePath(dirname(__DIR__));

    $closure = (new Schedule)->call(fn () => null);
    $line = __LINE__ - 1;
    $named = (new Schedule)->call(fn () => null)->name('named');

    expect(ScheduleModule::keys([$closure, $named]))->toBe(['tests/ScheduleModuleTest.php:'.$line, 'named']);
});

it('suffixes duplicate keys by position', function () {
    $schedule = new Schedule;
    $schedule->command('spyglass:test')->hourly();
    $schedule->exec('true');
    $schedule->command('spyglass:test')->daily();
    $schedule->command('spyglass:test')->weekly();

    expect(ScheduleModule::keys($schedule->events()))
        ->toBe(['php artisan spyglass:test', 'true', 'php artisan spyglass:test #2', 'php artisan spyglass:test #3']);
});

it('records runs under the keys computed from the schedule', function () {
    app(Schedule::class)->exec('true')->everyMinute();
    app(Schedule::class)->exec('true')->everyMinute();

    $this->artisan('schedule:run');

    expect(scheduleRuns()->pluck('task')->sort()->values()->all())->toBe(['true', 'true #2']);
});

it('prunes runs older than the retention', function () {
    app(Schedule::class)->exec('true')->everyMinute();
    $this->artisan('schedule:run');
    $this->travel(31)->days();
    $this->artisan('schedule:run');

    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(scheduleRuns())->toHaveCount(1)
        ->and(scheduleRuns()->first()->started_at)->toStartWith(now()->toDateString());
});
