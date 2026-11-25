<?php

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Schedule\ScheduleModule;
use Keepsuit\Spyglass\Modules\Schedule\ScheduleQueries;
use Keepsuit\Spyglass\Tests\Fixtures\WithScheduleModule;

uses(WithScheduleModule::class);

function insertRun(string $task, string $startedAt, string $status = 'success', string $host = 'web-1'): void
{
    DB::table(ScheduleModule::RUNS)->insert([
        'id' => (string) Str::ulid(),
        'task' => $task,
        'expression' => '* * * * *',
        'started_at' => $startedAt,
        'status' => $status,
        'host' => $host,
    ]);
}

function scheduledTask(string $key): array
{
    return app(ScheduleQueries::class)->tasks()->firstWhere('key', $key);
}

it('lists the scheduled tasks with their next and last run', function () {
    Date::setTestNow('2026-01-01 12:00:30');
    app(Schedule::class)->command('spyglass:test')->everyFiveMinutes()->description('Test');
    insertRun('php artisan spyglass:test', '2025-12-31 12:00:00', 'failed');
    insertRun('php artisan spyglass:test', '2026-01-01 12:00:02.500');

    expect(scheduledTask('php artisan spyglass:test'))->toMatchArray([
        'command' => 'php artisan spyglass:test',
        'description' => 'Test',
        'expression' => '*/5 * * * *',
        'missed' => false,
    ])
        ->and(scheduledTask('php artisan spyglass:test')['next_run']->toDateTimeString())->toBe('2026-01-01 12:05:00')
        ->and(scheduledTask('php artisan spyglass:test')['last_run'])->toMatchArray(['status' => 'success']);
});

it('reports a task missed once its due run is older than the grace period', function () {
    app(Schedule::class)->command('spyglass:test')->everyFiveMinutes();
    insertRun('php artisan spyglass:test', '2026-01-01 11:55:02');

    Date::setTestNow('2026-01-01 12:01:29');
    expect(scheduledTask('php artisan spyglass:test')['missed'])->toBeFalse();

    Date::setTestNow('2026-01-01 12:01:31');
    expect(scheduledTask('php artisan spyglass:test')['missed'])->toBeTrue();

    Date::setTestNow('2026-01-01 12:00:30');
    config()->set('spyglass.modules.schedule.missed_after', 0);
    expect(scheduledTask('php artisan spyglass:test')['missed'])->toBeTrue();
});

it('reports a task that never ran as missed', function () {
    app(Schedule::class)->command('spyglass:test')->everyMinute();

    expect(scheduledTask('php artisan spyglass:test'))->toMatchArray(['last_run' => null, 'missed' => true]);
});

it('does not report a task skipped by its filters as missed', function () {
    Date::setTestNow('2026-01-01 12:00:00');
    app(Schedule::class)->command('spyglass:test')->everyMinute()->when(false);

    $this->artisan('schedule:run');
    Date::setTestNow('2026-01-01 12:30:00');

    expect(scheduledTask('php artisan spyglass:test'))->toMatchArray(['missed' => false])
        ->and(scheduledTask('php artisan spyglass:test')['last_run'])->toMatchArray(['status' => 'skipped']);
});

it('does not report tasks of other environments as missed', function () {
    app(Schedule::class)->command('spyglass:test')->everyMinute()->environments('production');

    expect(scheduledTask('php artisan spyglass:test')['missed'])->toBeFalse();
});

it('computes the due time in the task timezone', function () {
    // 09:00 in New York is 14:00 UTC.
    app(Schedule::class)->command('spyglass:test')->dailyAt('09:00')->timezone('America/New_York');
    insertRun('php artisan spyglass:test', '2025-12-31 14:00:05');

    Date::setTestNow('2026-01-01 10:00:00');
    expect(scheduledTask('php artisan spyglass:test')['missed'])->toBeFalse();

    Date::setTestNow('2026-01-01 14:05:00');
    expect(scheduledTask('php artisan spyglass:test')['missed'])->toBeTrue();
});

it('loads the last runs of all tasks in one bounded query', function () {
    foreach (['a', 'b', 'c'] as $name) {
        app(Schedule::class)->command("spyglass:{$name}")->everyMinute();
        insertRun("php artisan spyglass:{$name}", '2026-01-01 11:00:00', 'failed');
        insertRun("php artisan spyglass:{$name}", '2026-01-01 12:00:00');
    }

    $queries = [];
    Spyglass::connection()->listen(function (QueryExecuted $query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $tasks = app(ScheduleQueries::class)->tasks();

    expect($queries)->toHaveCount(1)
        ->and(substr_count(strtolower($queries[0]), 'limit 1'))->toBe($tasks->count())
        ->and($tasks->pluck('last_run.status', 'key')->only(['php artisan spyglass:a', 'php artisan spyglass:b', 'php artisan spyglass:c'])->all())
        ->toBe(['php artisan spyglass:a' => 'success', 'php artisan spyglass:b' => 'success', 'php artisan spyglass:c' => 'success']);
});

it('paginates the runs of a task, newest first', function () {
    foreach (range(1, 5) as $minute) {
        insertRun('task', "2026-01-01 12:0{$minute}:00");
    }
    insertRun('other', '2026-01-01 13:00:00');

    $queries = [];
    Spyglass::connection()->listen(function (QueryExecuted $query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    $page = app(ScheduleQueries::class)->runs('task', perPage: 2, page: 2);

    expect($page->total())->toBe(5)
        ->and(collect($page->items())->pluck('started_at')->map(fn ($at) => substr($at, 0, 16))->all())
        ->toBe(['2026-01-01 12:03', '2026-01-01 12:02'])
        ->and(collect($queries)->reject(fn ($sql) => str_contains($sql, 'count(*)'))->every(fn ($sql) => str_contains($sql, 'limit')))->toBeTrue()
        ->and(app(ScheduleQueries::class)->runs('task', perPage: 1000)->perPage())->toBe(100);
});

it('includes tasks defined when Artisan starts, as `withSchedule()` does', function () {
    // As in a web request, where Artisan has not started yet.
    app(ConsoleKernel::class)->setArtisan(null);
    ConsoleApplication::starting(fn () => app(Schedule::class)->command('spyglass:test')->everyMinute());

    try {
        expect(app(ScheduleQueries::class)->tasks()->pluck('key'))->toContain('php artisan spyglass:test');
    } finally {
        ConsoleApplication::forgetBootstrappers();
    }
});
