<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Keepsuit\Spyglass\Dashboard\Widget;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionQueries;
use Keepsuit\Spyglass\Modules\Logs\LogQueries;
use Keepsuit\Spyglass\Modules\Queue\QueueQueries;
use Keepsuit\Spyglass\Modules\Schedule\ScheduleQueries;

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

    /**
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $modules = $this->spyglass->modules();
        $metrics = [];
        $charts = [];
        $tables = [];

        if (isset($modules['exceptions'])) {
            $groups = app(ExceptionQueries::class)->groups(['status' => 'unresolved'], perPage: self::COUNTED);
            $count = count($groups->items());

            $metrics[] = Widget::metric('unresolved_exceptions', 'Unresolved exceptions', $this->count($count, $groups->hasMorePages()), link: 'exceptions?status=unresolved', tone: $count > 0 ? 'error' : 'ok');
            $tables[] = Widget::table('exceptions', 'Unresolved exceptions', [
                Widget::field('class', 'Class'),
                Widget::field('message', 'Message'),
                Widget::field('count', 'Count', 'number'),
                Widget::field('last_seen_at', 'Last seen at', 'datetime'),
            ], array_map(ExceptionsController::group(...), $this->rows(array_slice($groups->items(), 0, self::LISTED))),
                link: 'exceptions/{fingerprint}', more: 'exceptions?status=unresolved', empty: 'No unresolved exceptions.', width: 'half');
        }

        if (isset($modules['queue'])) {
            $queries = app(QueueQueries::class);
            $from = $queries->rangeStart(24);
            $totals = $queries->totals($from, now(), [])->first() ?? [];
            $failed = $queries->failedJobs(perPage: self::LISTED);

            $metrics[] = Widget::metric('failed_jobs_total', 'Failed jobs', $failed->total(), link: 'queue', tone: $failed->total() > 0 ? 'error' : 'ok');
            $metrics[] = Widget::metric('processed_jobs', 'Jobs processed in 24h', $totals['processed'] ?? 0, link: 'queue');
            $charts[] = Widget::series('throughput', 'Queue throughput in 24h', 'bucket', [
                ['key' => 'processed', 'label' => QueueController::COUNTERS['processed']],
                ['key' => 'failed', 'label' => QueueController::COUNTERS['failed']],
            ], $queries->series($from, now()));
            $tables[] = Widget::table('failed_jobs', 'Failed jobs', [
                Widget::field('job', 'Job'),
                Widget::field('queue', 'Queue'),
                Widget::field('exception_class', 'Exception'),
                Widget::field('failed_at', 'Failed at', 'datetime'),
            ], $this->rows($failed->items()), link: 'queue/failed-jobs/{uuid}', more: 'queue', empty: 'No failed jobs.', width: 'half');
        }

        if (isset($modules['schedule'])) {
            $tasks = app(ScheduleQueries::class)->tasks()
                ->map(ScheduleController::flatten(...))
                ->filter(fn (array $task) => ! $task['stale'] && ($task['missed'] || in_array($task['last_status'], ['failed', 'interrupted'], true)))
                ->values();

            $metrics[] = Widget::metric('tasks_attention', 'Tasks needing attention', $tasks->count(), link: 'schedule', tone: $tasks->isEmpty() ? 'ok' : 'warning');
            $tables[] = Widget::table('tasks', 'Tasks needing attention', [
                Widget::field('label', 'Task'),
                Widget::field('last_status', 'Last run', 'status', ScheduleController::TONES),
                Widget::field('missed', 'Missed', 'boolean'),
                Widget::field('last_started_at', 'Last started at', 'datetime'),
            ], $tasks->take(self::LISTED), link: 'schedule/{key}', more: 'schedule', empty: 'Every task ran as scheduled.', width: 'half');
        }

        if (isset($modules['logs'])) {
            $entries = app(LogQueries::class)->entries(['from' => now()->subDay()], perPage: self::COUNTED);

            $metrics[] = Widget::metric('log_entries', 'Log entries in 24h', $this->count(count($entries->items()), $entries->hasMorePages()), link: 'logs');
            $tables[] = Widget::table('logs', 'Latest log entries', [
                Widget::field('level', 'Level', 'status', LogsController::TONES),
                Widget::field('message', 'Message'),
                Widget::field('logged_at', 'Logged at', 'datetime'),
            ], $this->rows(array_slice($entries->items(), 0, self::LISTED)), link: 'logs/{id}', more: 'logs', empty: 'No log entries in the last 24 hours.', width: 'half');
        }

        return Widget::page('Overview', [...$metrics, ...$charts, ...$tables]);
    }

    protected function count(int $count, bool $more): int|string
    {
        return $more ? "{$count}+" : $count;
    }
}
