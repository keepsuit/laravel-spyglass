<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;
use Keepsuit\Spyglass\Modules\Logs\LogsModule;
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
function sourcesOf(string $table): array
{
    return DB::table($table)->orderBy('id')->get()->map(fn ($row) => [$row->source_type, $row->source, $row->source_id])->all();
}

it('attributes what a scheduled task reports and logs to its run, including the exception that failed it', function () {
    app(Schedule::class)->call(function () {
        Log::warning('Halfway');

        throw new RuntimeException('Task failed');
    })->name('sync orders')->everyMinute();

    test()->artisan('schedule:run');
    Spyglass::flush();

    $run = DB::table(ScheduleModule::RUNS)->sole();

    expect(sourcesOf(ExceptionsModule::OCCURRENCES))->toBe([['schedule', 'sync orders', $run->id]])
        ->and(sourcesOf(LogsModule::ENTRIES))->toBe([['schedule', 'sync orders', $run->id]]);
});

it('attributes the failure of a command that exits with an error to its run', function () {
    app(Schedule::class)->exec('exit 3')->everyMinute();

    test()->artisan('schedule:run');
    Spyglass::flush();

    expect(sourcesOf(ExceptionsModule::OCCURRENCES))->toBe([['schedule', 'exit 3', DB::table(ScheduleModule::RUNS)->value('id')]]);
});

it('stops attributing entries to a task once it finished', function () {
    app(Schedule::class)->call(fn () => null)->name('quiet')->everyMinute();

    test()->artisan('schedule:run');
    Log::warning('Afterwards');
    Spyglass::flush();

    expect(sourcesOf(LogsModule::ENTRIES)[0][0])->toBe('command');
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

    expect(sourcesOf(LogsModule::ENTRIES))->toBe([['schedule', 'printenv __LARAVEL_CONTEXT', DB::table(ScheduleModule::RUNS)->value('id')]]);
})->with(['foreground' => false, 'background' => true]);

it('keeps the source of a command task out of the scheduler process once it ended', function () {
    app(Schedule::class)->exec('true')->everyMinute();
    app(Schedule::class)->exec('exit 3')->everyMinute();

    test()->artisan('schedule:run');

    expect(Context::hasHidden(Sources::CONTEXT_SOURCE))->toBeFalse();
});
