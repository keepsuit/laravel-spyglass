<?php

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Tests\Fixtures\TestJob;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function names(): array
{
    return DB::table('spyglass_test_entries')->orderBy('id')->pluck('name')->all();
}

function runNextJob(): void
{
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(maxTries: 1));
}

it('flushes a request when it terminates', function () {
    Route::get('/record', function () {
        Spyglass::insert('spyglass_test_entries', ['name' => 'request']);
        TestJob::dispatchSync('sync-job');

        return DB::table('spyglass_test_entries')->count();
    });

    $this->get('/record')->assertContent('0');
    expect(names())->toBe(['request', 'sync-job']);

    $this->get('/record')->assertContent('2');
    expect(names())->toHaveCount(4);
});

it('keeps responding when storage fails', function () {
    Route::get('/record', function () {
        Spyglass::insert('spyglass_missing', ['name' => 'request']);

        return 'ok';
    });

    $this->get('/record')->assertOk()->assertContent('ok');

    expect($this->spyglassErrors)->toHaveCount(1);
    $this->spyglassErrors = [];
});

it('flushes after each queued job', function () {
    TestJob::dispatch('job');

    runNextJob();

    expect(names())->toBe(['job']);
});

it('flushes after a failed job, including entries recorded by failure listeners', function () {
    TestJob::dispatch('job', fail: true);

    runNextJob();

    expect(names())->toBe(['job', 'job-failed']);
});

it('flushes entries left over from a previous lifecycle when a job starts', function () {
    TestJob::dispatch('job');
    Spyglass::insert('spyglass_test_entries', ['name' => 'leftover']);

    runNextJob();
    Spyglass::flush();

    expect(names())->toBe(['leftover', 'job']);
});

it('flushes when the worker stops', function () {
    Spyglass::insert('spyglass_test_entries', ['name' => 'timed-out']);

    event(new WorkerStopping(1));

    expect(names())->toBe(['timed-out']);
});

it('flushes when a command finishes', function () {
    Spyglass::insert('spyglass_test_entries', ['name' => 'command']);

    event(new CommandFinished('test', new ArrayInput([]), new NullOutput, 0));

    expect(names())->toBe(['command']);
});

it('flushes when the application terminates', function () {
    Spyglass::insert('spyglass_test_entries', ['name' => 'console']);

    $this->app->terminate();

    expect(names())->toBe(['console']);
});

it('flushes after each scheduled task', function () {
    $this->travelTo(now()->setTime(12, 0));

    $schedule = app(Schedule::class);
    $schedule->call(fn () => Spyglass::insert('spyglass_test_entries', ['name' => 'first']))->everyMinute();
    $schedule->call(fn () => Spyglass::insert('spyglass_test_entries', [
        'name' => 'second saw '.DB::table('spyglass_test_entries')->count(),
    ]))->everyMinute();

    $this->artisan('schedule:run')->assertSuccessful();

    expect(names())->toBe(['first', 'task-finished', 'second saw 2', 'task-finished']);
});
