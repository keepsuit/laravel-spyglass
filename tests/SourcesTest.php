<?php

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Schedule\ScheduleModule;
use Keepsuit\Spyglass\Support\Sources;
use Keepsuit\Spyglass\Tests\Fixtures\WithAllModules;

uses(WithAllModules::class);

beforeEach(function () {
    app(ConsoleKernel::class)->rerouteSymfonyCommandEvents()->setArtisan(null);
});

/**
 * @return list<array{string, ?string, ?string}>
 */
function sourcesOf(Builder $table): array
{
    return $table->orderBy('id')->get()->map(fn ($row) => [$row->source_type, $row->source, $row->source_id])->all();
}

it('attributes what a scheduled task reports and logs to its run, including the exception that failed it', function () {
    app(Schedule::class)->call(function () {
        Log::warning('Halfway');

        throw new RuntimeException('Task failed');
    })->name('sync orders')->everyMinute();

    test()->artisan('schedule:run');
    Spyglass::flush();

    $run = DB::table(ScheduleModule::RUNS)->sole();

    expect(sourcesOf(exceptionOccurrencesTable()))->toBe([['schedule', 'sync orders', $run->id]])
        ->and(sourcesOf(logEntriesTable()))->toBe([['schedule', 'sync orders', $run->id]]);
});

it('attributes the failure of a command that exits with an error to its run', function () {
    app(Schedule::class)->exec('exit 3')->everyMinute();

    test()->artisan('schedule:run');
    Spyglass::flush();

    expect(sourcesOf(exceptionOccurrencesTable()))->toBe([['schedule', 'exit 3', DB::table(ScheduleModule::RUNS)->value('id')]]);
});

it('stops attributing entries to a task once it finished', function () {
    app(Schedule::class)->call(fn () => null)->name('quiet')->everyMinute();

    test()->artisan('schedule:run');
    Log::warning('Afterwards');
    Spyglass::flush();

    expect(sourcesOf(logEntriesTable())[0][0])->toBe('command');
});

it('attributes what the process of a command task logs to its run', function (bool $background) {
    $file = tempnam(sys_get_temp_dir(), 'spyglass');
    $task = app(Schedule::class)->exec('printenv __LARAVEL_CONTEXT')->everyMinute()->sendOutputTo($file);

    if ($background) {
        $task->runInBackground();
    }

    test()->artisan('schedule:run');

    $deadline = microtime(true) + 10;
    while (trim((string) file_get_contents($file)) === '' && microtime(true) < $deadline) {
        usleep(20_000);
    }

    // As Laravel restores it in the background process.
    Context::hydrate(json_decode(trim((string) file_get_contents($file)), true));
    Log::warning('In the background');
    Spyglass::flush();

    expect(sourcesOf(logEntriesTable()))->toBe([['schedule', 'printenv __LARAVEL_CONTEXT', DB::table(ScheduleModule::RUNS)->value('id')]]);
})->with(['foreground' => false, 'background' => true]);

it('forgets the task a job inherited once the job ended', function () {
    Context::addHidden(Sources::CONTEXT_SOURCE, ['source' => 'sync orders', 'source_id' => 'run-1']);
    dispatch(fn () => null);
    Context::forgetHidden(Sources::CONTEXT_SOURCE);

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    Log::warning('After the job');
    Spyglass::flush();

    expect(sourcesOf(logEntriesTable())[0][0])->toBe('command');
});

it('links the exception of an overlap skip to no run, since the skip is not one', function () {
    $task = app(Schedule::class)->exec('exit 1')->everyTenSeconds();

    // Laravel reuses the task object: the skip keeps the exit code of the failed execution and throws again.
    event(new ScheduledTaskStarting($task));
    $task->exitCode = 1;
    event(new ScheduledTaskFinished($task, 0.0));
    event(new ScheduledTaskFailed($task, $failed = new RuntimeException('failed'), 0.0));
    report($failed);

    event(new ScheduledTaskStarting($task));
    $task->skippedBecauseOverlapping = true;
    event(new ScheduledTaskFinished($task, 0.0));
    event(new ScheduledTaskFailed($task, $skipped = new LogicException('skipped'), 0.0));
    report($skipped);
    Spyglass::flush();

    expect(sourcesOf(exceptionOccurrencesTable()))->toBe([
        ['schedule', 'exit 1', DB::table(ScheduleModule::RUNS)->value('id')],
        ['schedule', 'exit 1', null],
    ]);
})->skip(! property_exists(Event::class, 'skippedBecauseOverlapping'), 'Requires the overlap flag');

it('keeps the source of a command task out of the scheduler process once it ended', function () {
    app(Schedule::class)->exec('true')->everyMinute();
    app(Schedule::class)->exec('exit 3')->everyMinute();

    test()->artisan('schedule:run');

    expect(Context::hasHidden(Sources::CONTEXT_SOURCE))->toBeFalse();
});
