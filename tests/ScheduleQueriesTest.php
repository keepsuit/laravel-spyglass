<?php

use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Schedule\ScheduleModule;
use Keepsuit\Spyglass\Modules\Schedule\ScheduleQueries;
use Keepsuit\Spyglass\Tests\Fixtures\WithScheduleModule;

uses(WithScheduleModule::class);

beforeEach(function () {
    Date::setTestNow('2026-01-01 12:00:30');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function insertTask(string $label, string $expression = '* * * * *', array $attributes = []): void
{
    DB::table(ScheduleModule::TASKS)->insert([
        'key' => sha1($label),
        'label' => $label,
        'expression' => $expression,
        'timezone' => 'UTC',
        'first_seen_at' => '2025-01-01 00:00:00',
        'last_seen_at' => Date::now('UTC')->format('Y-m-d H:i:s'),
        ...$attributes,
    ]);
}

function insertRun(string $label, string $startedAt, string $status = 'success', string $host = 'web-1'): void
{
    DB::table(ScheduleModule::RUNS)->insert([
        'id' => (string) Str::ulid(),
        'task' => sha1($label),
        'started_at' => $startedAt,
        'status' => $status,
        'host' => $host,
    ]);
}

function scheduledTask(string $label): array
{
    return app(ScheduleQueries::class)->tasks()->firstWhere('label', $label);
}

it('lists the published tasks with their next and last run', function () {
    insertTask('report', '*/5 * * * *', ['command' => 'php artisan report', 'description' => 'Report', 'environments' => '["testing"]']);
    insertRun('report', '2025-12-31 12:00:00', 'failed');
    insertRun('report', '2026-01-01 12:00:02.500');

    expect(scheduledTask('report'))->toMatchArray([
        'key' => sha1('report'),
        'command' => 'php artisan report',
        'description' => 'Report',
        'expression' => '*/5 * * * *',
        'environments' => ['testing'],
        'stale' => false,
        'missed' => false,
    ])
        ->and(scheduledTask('report')['next_run']->toDateTimeString())->toBe('2026-01-01 12:05:00')
        ->and(scheduledTask('report')['last_run'])->toMatchArray(['status' => 'success']);
});

it('reads only the published tables, without starting Artisan', function () {
    app(ConsoleKernel::class)->setArtisan(null);
    app(Schedule::class)->command('spyglass:unpublished')->everyMinute();
    insertTask('report');

    $started = false;
    Event::listen(ArtisanStarting::class, function () use (&$started) {
        $started = true;
    });

    expect(app(ScheduleQueries::class)->tasks()->pluck('label')->all())->toBe(['report'])
        ->and($started)->toBeFalse();
});

it('reports a task missed once its due run is older than the grace period', function () {
    insertTask('report', '*/5 * * * *');
    insertRun('report', '2026-01-01 11:55:02');

    Date::setTestNow('2026-01-01 12:01:29');
    expect(scheduledTask('report')['missed'])->toBeFalse();

    Date::setTestNow('2026-01-01 12:01:31');
    expect(scheduledTask('report')['missed'])->toBeTrue();

    Date::setTestNow('2026-01-01 12:00:30');
    config()->set('spyglass.modules.schedule.missed_after', 0);
    expect(scheduledTask('report')['missed'])->toBeTrue();
});

it('reports a task that never ran as missed only once a slot passed after it was first seen', function () {
    insertTask('report', '*/5 * * * *', ['first_seen_at' => '2026-01-01 12:01:00']);

    Date::setTestNow('2026-01-01 12:06:29');
    expect(scheduledTask('report'))->toMatchArray(['last_run' => null, 'missed' => false]);

    Date::setTestNow('2026-01-01 12:06:31');
    expect(scheduledTask('report')['missed'])->toBeTrue();
});

it('counts a skip at or after the due time as satisfying it', function () {
    insertTask('report', '*/5 * * * *', ['last_skipped_at' => '2026-01-01 12:00:00', 'last_skip_reason' => 'condition_not_met']);
    insertRun('report', '2026-01-01 10:00:00');

    Date::setTestNow('2026-01-01 12:04:00');
    expect(scheduledTask('report'))->toMatchArray(['missed' => false, 'last_skip_reason' => 'condition_not_met'])
        ->and(scheduledTask('report')['last_run']['started_at'])->toStartWith('2026-01-01 10:00:00');

    Date::setTestNow('2026-01-01 12:06:31');
    expect(scheduledTask('report')['missed'])->toBeTrue();
});

it('does not report tasks of other environments as missed', function () {
    insertTask('report', attributes: ['environments' => '["production"]']);

    expect(scheduledTask('report')['missed'])->toBeFalse();
});

it('computes the due time in the task timezone, across both repeated hours of a fall-back night', function () {
    // On 2026-10-25 Rome goes from 03:00 CEST back to 02:00 CET: 02:30 happens at 00:30 and at 01:30 UTC.
    insertTask('report', '30 2 * * *', ['timezone' => 'Europe/Rome']);
    insertRun('report', '2026-10-25 00:30:05');

    Date::setTestNow('2026-10-25 00:40:00');
    expect(scheduledTask('report')['missed'])->toBeFalse()
        ->and(scheduledTask('report')['next_run']->utc()->toDateTimeString())->toBe('2026-10-25 01:30:00');

    Date::setTestNow('2026-10-25 01:35:00');
    expect(scheduledTask('report')['missed'])->toBeTrue();

    insertRun('report', '2026-10-25 01:30:05');
    expect(scheduledTask('report')['missed'])->toBeFalse()
        ->and(scheduledTask('report')['next_run']->utc()->toDateTimeString())->toBe('2026-10-26 01:30:00');
});

it('computes the next run and the due time of sub-minute tasks from their interval', function () {
    insertTask('poll', '* * * * *', ['repeat_seconds' => 10]);
    insertTask('weekly poll', '* * * * 1', ['repeat_seconds' => 30]);
    insertRun('poll', '2026-01-01 11:59:00');

    expect(scheduledTask('poll')['next_run']->toDateTimeString())->toBe('2026-01-01 12:00:40')
        // 2026-01-01 is a Thursday.
        ->and(scheduledTask('weekly poll')['next_run']->toDateTimeString())->toBe('2026-01-05 00:00:00');

    // The latest slot older than the grace period is 11:59:00.
    Date::setTestNow('2026-01-01 12:00:39');
    expect(scheduledTask('poll')['missed'])->toBeFalse();

    // Now it is 11:59:10.
    Date::setTestNow('2026-01-01 12:00:41');
    expect(scheduledTask('poll')['missed'])->toBeTrue();
});

it('shows tasks no longer published as stale, never as missed', function () {
    insertTask('removed', attributes: ['last_seen_at' => '2026-01-01 11:00:00']);
    insertTask('current');

    expect(scheduledTask('removed'))->toMatchArray(['stale' => true, 'missed' => false, 'next_run' => null])
        ->and(scheduledTask('current'))->toMatchArray(['stale' => false, 'missed' => true]);
});

it('detects stale tasks from the newest publication, even beyond the listed tasks', function () {
    foreach (array_chunk(range(1, 1000), 200) as $chunk) {
        DB::table(ScheduleModule::TASKS)->insert(array_map(fn (int $i) => [
            'key' => sha1("removed-{$i}"),
            'label' => sprintf('a-removed-%04d', $i),
            'expression' => '* * * * *',
            'timezone' => 'UTC',
            'first_seen_at' => '2025-01-01 00:00:00',
            'last_seen_at' => '2026-01-01 11:00:00',
        ], $chunk));
    }
    insertTask('z-current');

    expect(app(ScheduleQueries::class)->tasks()->first())->toMatchArray(['stale' => true, 'missed' => false]);
});

it('does not expect a run in the hour skipped by a spring-forward night', function () {
    // 02:30 does not exist in Rome on 2026-03-29: Laravel never runs the task that day.
    Date::setTestNow('2026-03-29 06:00:00');
    insertTask('nightly', '30 2 * * *', ['timezone' => 'Europe/Rome']);
    insertRun('nightly', '2026-03-28 01:30:00');

    expect(scheduledTask('nightly')['missed'])->toBeFalse()
        ->and(scheduledTask('nightly')['next_run']->copy()->utc()->toDateTimeString())->toBe('2026-03-30 00:30:00');
});

it('reads the tasks when the application uses immutable dates', function () {
    Date::use(CarbonImmutable::class);

    try {
        insertTask('report');
        insertRun('report', '2026-01-01 12:00:00');

        expect(scheduledTask('report'))->toMatchArray(['stale' => false, 'missed' => false]);
    } finally {
        Date::useDefault();
    }
});

it('shows every task as missed, not stale, when the scheduler stopped', function () {
    insertTask('a', attributes: ['last_seen_at' => '2026-01-01 11:00:00']);
    insertTask('b', attributes: ['last_seen_at' => '2026-01-01 11:00:00']);

    expect(app(ScheduleQueries::class)->tasks()->map(fn ($task) => [$task['stale'], $task['missed']])->all())
        ->toBe([[false, true], [false, true]]);
});

it('shows a run that never finished as interrupted after the threshold', function () {
    insertTask('report');
    insertRun('report', '2025-12-31 12:30:00', 'running');

    expect(scheduledTask('report')['last_run']['status'])->toBe('running');

    config()->set('spyglass.modules.schedule.interrupted_after', 3600);
    expect(scheduledTask('report')['last_run']['status'])->toBe('interrupted');
});

it('shows a running run superseded by a later one as interrupted', function () {
    insertRun('report', '2026-01-01 11:58:00', 'running');
    insertRun('report', '2026-01-01 11:59:00', 'success');
    insertRun('report', '2026-01-01 12:00:00', 'running');

    expect(collect(app(ScheduleQueries::class)->runs(sha1('report'))->items())->pluck('status')->all())
        ->toBe(['running', 'success', 'interrupted'])
        ->and(collect(app(ScheduleQueries::class)->runs(sha1('report'), perPage: 1, page: 1)->items())->pluck('status')->all())
        ->toBe(['running'])
        ->and(collect(app(ScheduleQueries::class)->runs(sha1('report'), perPage: 1, page: 3)->items())->pluck('status')->all())
        ->toBe(['interrupted']);
});

it('loads the tasks and their last runs in two bounded queries', function () {
    foreach (['a', 'b', 'c'] as $name) {
        insertTask($name);
        insertRun($name, '2026-01-01 11:00:00', 'failed');
        insertRun($name, '2026-01-01 12:00:00');
    }

    $queries = [];
    Spyglass::connection()->listen(function (QueryExecuted $query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    $tasks = app(ScheduleQueries::class)->tasks();

    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toContain('limit')
        ->and(substr_count($queries[1], 'limit 1'))->toBe(3)
        ->and($tasks->pluck('last_run.status', 'label')->all())->toBe(['a' => 'success', 'b' => 'success', 'c' => 'success']);
});

it('paginates the runs of a task, newest first, without counting them', function () {
    foreach (range(1, 5) as $minute) {
        insertRun('task', "2026-01-01 12:0{$minute}:00");
    }
    insertRun('other', '2026-01-01 13:00:00');

    $queries = [];
    Spyglass::connection()->listen(function (QueryExecuted $query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    $page = app(ScheduleQueries::class)->runs(sha1('task'), perPage: 2, page: 2);

    expect($page->hasMorePages())->toBeTrue()
        ->and(collect($page->items())->pluck('started_at')->map(fn ($at) => substr($at, 0, 16))->all())
        ->toBe(['2026-01-01 12:03', '2026-01-01 12:02'])
        ->and($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('limit')
        ->and($queries[0])->not->toContain('count(')
        ->and(app(ScheduleQueries::class)->runs('task', perPage: 1000)->perPage())->toBe(100);
});

it('reports an expression it cannot parse instead of failing', function () {
    insertTask('broken', 'not a cron expression');

    expect(scheduledTask('broken'))->toMatchArray(['next_run' => null, 'missed' => false])
        ->and(collect($this->spyglassErrors)->pluck('message')->every(fn ($message) => str_contains($message, '[broken]')))->toBeTrue()
        ->and($this->spyglassErrors)->not->toBeEmpty();

    $this->spyglassErrors = [];
});
