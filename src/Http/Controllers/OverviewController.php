<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Keepsuit\Spyglass\Dashboard\Widget;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionQueries;
use Keepsuit\Spyglass\Modules\Logs\LogQueries;
use Keepsuit\Spyglass\Modules\Queue\QueueQueries;
use Keepsuit\Spyglass\Modules\Schedule\ScheduleQueries;
use Keepsuit\Spyglass\Support\Hourly;

/**
 * The home page: what needs attention in each enabled module. Health is left out: its checks can take
 * seconds when a service is down, and the overview refreshes every minute.
 *
 * @internal
 */
class OverviewController extends Controller
{
    protected const int LISTED = 5;

    /**
     * Counted on the first page: the count reads `100+` past it.
     */
    protected const int COUNTED = 100;

    /** Tasks listed, so an application with many tasks keeps a short card. */
    protected const int TASKS = 10;

    /**
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $modules = $this->spyglass->modules();
        $metrics = [];
        $groups = [];

        if (isset($modules['exceptions'])) {
            $queries = app(ExceptionQueries::class);
            $unresolved = $queries->groups(['status' => 'unresolved'], perPage: self::COUNTED);
            $count = count($unresolved->items());

            $metrics[] = Widget::metric('unresolved_exceptions', 'Unresolved exceptions', $this->count($count, $unresolved->hasMorePages()), link: 'exceptions?status=unresolved', tone: $count > 0 ? 'error' : 'ok');
            $groups['exceptions'] = Widget::group('exceptions', 'Exceptions', [
                Widget::series('exceptions_per_hour', 'Per hour', 'bucket', [['key' => 'count', 'label' => 'Exceptions', 'tone' => 'error']],
                    $queries->hourly(Hourly::start(24)), style: 'bars', note: ExceptionsController::NOTE),
                Widget::table('unresolved', 'Unresolved', [
                    Widget::field('class', 'Class'),
                    Widget::field('message', 'Message'),
                    Widget::field('count', 'Count', 'number'),
                    Widget::field('last_seen_at', 'Last seen at', 'datetime'),
                ], array_map(ExceptionsController::group(...), $this->rows(array_slice($unresolved->items(), 0, self::LISTED))),
                    link: 'exceptions/{fingerprint}', empty: 'No unresolved exceptions.'),
            ], more: 'exceptions?status=unresolved', width: 'half');
        }

        if (isset($modules['queue'])) {
            $queries = app(QueueQueries::class);
            $from = $queries->rangeStart(24);
            $totals = $queries->totals($from, now(), [])->first() ?? [];
            $previous = app(QueueController::class)->previousTotals($from, now());
            $failed = $queries->failedJobs(perPage: self::LISTED);

            $metrics[] = Widget::metric('failed_jobs_total', 'Failed jobs', $failed->total(), link: 'queue', tone: $failed->total() > 0 ? 'error' : 'ok');
            $metrics[] = Widget::metric('processed_jobs', 'Jobs processed in 24h', $totals['processed'] ?? 0, link: 'queue', previous: $previous['processed'] ?? null);
            $groups['queue'] = Widget::group('queue', 'Queue', [
                Widget::series('throughput', 'Throughput in 24h', 'bucket', [
                    ['key' => 'processed', 'label' => QueueController::COUNTERS['processed']],
                    ['key' => 'failed', 'label' => QueueController::COUNTERS['failed']],
                ], $queries->series($from, now())),
                Widget::table('failed_jobs', 'Latest failed jobs', [
                    Widget::field('job', 'Job'),
                    Widget::field('queue', 'Queue'),
                    Widget::field('exception_class', 'Exception'),
                    Widget::field('failed_at', 'Failed at', 'datetime'),
                ], $this->rows($failed->items()), link: 'queue/failed-jobs/{uuid}', empty: 'No failed jobs.'),
            ], more: 'queue', width: 'half');
        }

        if (isset($modules['schedule'])) {
            $tasks = app(ScheduleQueries::class)->tasks()
                ->map(ScheduleController::flatten(...))
                ->reject(fn (array $task) => $task['stale'])
                ->map(fn (array $task) => [...$task, 'attention' => $task['missed'] || in_array($task['last_status'], ['failed', 'interrupted'], true)]);
            $attention = $tasks->where('attention', true)->count();

            $metrics[] = Widget::metric('tasks_attention', 'Tasks needing attention', $attention, link: 'schedule', tone: $attention === 0 ? 'ok' : 'warning');
            $groups['schedule'] = Widget::group('schedule', 'Schedule', [
                Widget::table('tasks', 'Tasks', [
                    Widget::field('label', 'Task'),
                    Widget::field('last_status', 'Last run', 'status', ScheduleController::TONES),
                    Widget::field('recent', 'Recent runs', 'runs', ScheduleController::TONES),
                    Widget::field('missed', 'Missed', 'boolean'),
                    Widget::field('next_run', 'Next run', 'datetime'),
                ], $tasks->sortBy(fn (array $task) => $task['attention'] ? 0 : 1)->take(self::TASKS)->values(), link: 'schedule/{key}', empty: 'No scheduled tasks yet.'),
            ], more: 'schedule', width: 'half');
        }

        if (isset($modules['logs'])) {
            $queries = app(LogQueries::class);
            $entries = $queries->entries(['from' => now()->subDay()], perPage: self::COUNTED);

            $metrics[] = Widget::metric('log_entries', 'Log entries in 24h', $this->count(count($entries->items()), $entries->hasMorePages()), link: 'logs');
            $groups['logs'] = Widget::group('logs', 'Logs', [
                LogsController::chart($queries->hourly(Hourly::start(24)), 'Per hour'),
                Widget::table('latest_logs', 'Latest entries', [
                    Widget::field('level', 'Level', 'status', LogsController::TONES),
                    Widget::field('message', 'Message'),
                    Widget::field('logged_at', 'Logged at', 'datetime'),
                ], $this->rows(array_slice($entries->items(), 0, self::LISTED)), link: 'logs/{id}', empty: 'No log entries in the last 24 hours.'),
            ], more: 'logs', width: 'half');
        }

        // Charts with a time axis first, side by side.
        $order = array_flip(['queue', 'exceptions', 'logs', 'schedule']);
        uksort($groups, fn (string $a, string $b) => $order[$a] <=> $order[$b]);

        return Widget::page('Overview', [...$metrics, ...array_values($groups)]);
    }

    protected function count(int $count, bool $more): int|string
    {
        return $more ? "{$count}+" : $count;
    }
}
