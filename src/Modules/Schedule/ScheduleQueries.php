<?php

namespace Keepsuit\Spyglass\Modules\Schedule;

use Carbon\CarbonInterface;
use Closure;
use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Paginator as SimplePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Keepsuit\Spyglass\Spyglass;
use stdClass;
use Throwable;

/**
 * Read side of the schedule module: only the tables the scheduler writes, never the console kernel.
 * Every query is bounded. Datetimes are UTC strings, as stored.
 */
class ScheduleQueries
{
    /** Tasks per last-run query: SQLite allows at most 500 terms in a compound select. */
    protected const int LAST_RUNS_CHUNK = 100;

    protected const int MAX_TASKS = 1000;

    protected const int RECENT_RUNS = 10;

    /** A task the scheduler stopped publishing this long before the newest publish was removed from the schedule. */
    protected const int STALE_AFTER = 300;

    public function __construct(protected Spyglass $spyglass) {}

    /**
     * Tasks published by the scheduler with their next run, last run and whether a due run is missing.
     * A task is missed when its latest slot older than the grace period has neither a run nor a skip
     * since, and passed after the task was first seen. Stale tasks are never missed. Tasks that do not run
     * in the current environment are left out: they never fire any event here.
     *
     * Each task has the columns of its row, `environments` decoded, and `stale`, `next_run` (null when
     * stale), `last_run`, `missed` and `recent`: the `status` and `started_at` of its latest runs, oldest
     * first, or null for a sub-minute task, whose successful runs are not kept.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function tasks(): Collection
    {
        $tasks = $this->spyglass->connection()->table(ScheduleModule::TASKS)
            ->select('*')
            // Over every task: the newest publication may be beyond the listed ones.
            ->selectSub(fn (Builder $query) => $query->from(ScheduleModule::TASKS)->selectRaw('max(last_seen_at)'), 'newest')
            ->orderBy('label')
            ->orderBy('detail')
            ->orderBy('key')
            ->limit(self::MAX_TASKS)
            ->get()
            ->filter(fn (object $task) => $task->environments === null || in_array(app()->environment(), (array) json_decode($task->environments, true), true))
            ->values();

        // No more than the retention keeps of each outcome, or pruned successes would leave only failures.
        $recent = min(self::RECENT_RUNS, ...array_map(fn (string $kept) => max(2, (int) config("spyglass.modules.schedule.retention.{$kept}", self::RECENT_RUNS)), ['successful_runs_per_task', 'failed_runs_per_task']));
        $lastRuns = $this->lastRuns($tasks->pluck('key')->all(), $recent);
        $newest = is_string($tasks->first()?->newest) ? $this->date($tasks->first()->newest) : Date::now();

        return $tasks->map(fn (object $task) => $this->task($task, $lastRuns[$task->key] ?? [], $newest));
    }

    /**
     * @param  list<array<string, mixed>>  $runs  newest first
     * @return array<string, mixed>
     */
    protected function task(stdClass $task, array $runs, CarbonInterface $newest): array
    {
        [$lastRun, $previousRun] = [$runs[0] ?? null, $runs[1] ?? null];
        $schedules = array_map(fn (array $schedule) => [
            'expression' => (string) $schedule['expression'],
            'timezone' => (string) $schedule['timezone'],
            'repeat_seconds' => isset($schedule['repeat_seconds']) ? (int) $schedule['repeat_seconds'] : null,
        ], (array) json_decode($task->schedules, true));
        $repeats = array_filter(array_column($schedules, 'repeat_seconds')) !== [];
        $recent = $repeats ? null : array_reverse(array_map(fn (array $run, int $i) => [
            'status' => $this->status($run, superseded: (bool) $task->without_overlapping && $i > 0),
            'started_at' => $run['started_at'],
        ], $runs, array_keys($runs)));

        $overlapping = $lastRun !== null && $this->overlapped($lastRun, $previousRun, (bool) $task->without_overlapping);

        $stale = $this->date($task->last_seen_at)->lt($newest->copy()->subSeconds(self::STALE_AFTER));

        if ($lastRun !== null) {
            $lastRun['status'] = $this->status($lastRun, superseded: false);
        }

        // Successful runs of sub-minute tasks are kept only on the task row.
        if ($task->last_run_at !== null && ($lastRun === null || $this->date($task->last_run_at)->gt($this->date($lastRun['started_at'])))) {
            $lastRun = [
                'task' => $task->key,
                'status' => 'success',
                'started_at' => $task->last_run_at,
                'finished_at' => null,
                'duration_ms' => (int) $task->last_duration_ms,
                'exit_code' => 0,
            ];
            $overlapping = false;
        }

        $task = [
            'key' => $task->key,
            'label' => $task->label,
            'type' => $task->type,
            'detail' => $task->detail,
            'command' => $task->command,
            'description' => $task->description,
            'schedules' => $schedules,
            'environments' => $task->environments === null ? [] : (array) json_decode($task->environments, true),
            'without_overlapping' => (bool) $task->without_overlapping,
            'first_seen_at' => $task->first_seen_at,
            'last_seen_at' => $task->last_seen_at,
            'last_skipped_at' => $task->last_skipped_at,
            'last_skip_reason' => $task->last_skip_reason,
            'stale' => $stale,
            'last_run' => $lastRun,
            'overlapping' => $overlapping,
            'recent' => $recent,
        ];

        return [...$task, 'next_run' => $stale ? null : $this->nextRun($task), 'missed' => ! $stale && $this->missed($task)];
    }

    /**
     * Runs of a task, newest first. A run still `running` is `interrupted` when it is older than
     * `interrupted_after` or, for a task without overlapping, when a later one started. A run is
     * `overlapped` when it started before the previous one finished: usually a bug, such as a missing
     * `withoutOverlapping()` or `onOneServer()`.
     *
     * @return Paginator<int, stdClass>
     */
    public function runs(string $task, int $perPage = 25, int $page = 1): Paginator
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        // One more: it tells whether there are more pages and whether the last run overlapped it.
        $rows = $this->runsOf($task)
            ->select(ScheduleModule::RUNS.'.*')
            ->selectSub(fn (Builder $query) => $query->from(ScheduleModule::TASKS)->where('key', $task)->select('without_overlapping'), 'without_overlapping')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get()
            ->all();

        $runs = [];

        foreach (array_slice($rows, 0, $perPage) as $i => $run) {
            $run->overlapped = $this->overlapped((array) $run, isset($rows[$i + 1]) ? (array) $rows[$i + 1] : null, (bool) $run->without_overlapping);
            $run->status = $this->status((array) $run, superseded: (bool) $run->without_overlapping && ($page > 1 || $i > 0));
            unset($run->without_overlapping);
            $runs[] = $run;
        }

        // The paginator tells there are more pages from an item beyond `$perPage`, which it leaves out.
        return new SimplePaginator(count($rows) > $perPage ? [...$runs, $rows[$perPage]] : $runs, $perPage, $page, ['path' => SimplePaginator::resolveCurrentPath()]);
    }

    /**
     * Whether `$run` started while `$previous`, the run before it, was still executing. A run that never
     * finished counts until `interrupted_after`, unless the task prevents overlaps: then it was interrupted.
     *
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>|null  $previous
     */
    protected function overlapped(array $run, ?array $previous, bool $withoutOverlapping): bool
    {
        if ($previous === null) {
            return false;
        }

        $started = $this->date($run['started_at']);

        if ($previous['finished_at'] !== null) {
            return $this->date($previous['finished_at'])->gt($started);
        }

        return ! $withoutOverlapping && $previous['status'] === 'running'
            && $this->date($previous['started_at'])->gt($started->copy()->subSeconds((int) config('spyglass.modules.schedule.interrupted_after', 86400)));
    }

    /**
     * The newest runs of each task, newest first: one `LIMIT` index lookup per task, in a single query per chunk.
     *
     * @param  array<mixed>  $keys
     * @return array<string, list<array<string, mixed>>>
     */
    protected function lastRuns(array $keys, int $limit): array
    {
        $runs = [];

        foreach (array_chunk($keys, self::LAST_RUNS_CHUNK) as $chunk) {
            $query = null;

            foreach ($chunk as $key) {
                $last = $this->runsOf($key)
                    ->select(['id', 'task', 'status', 'started_at', 'finished_at', 'duration_ms', 'exit_code'])
                    ->limit($limit);
                $query = $query ? $query->unionAll($last) : $last;
            }

            foreach ($query->get() as $run) {
                $runs[$run->task][] = (array) $run;
            }
        }

        // A union keeps no order.
        foreach ($runs as &$pair) {
            usort($pair, fn (array $a, array $b) => [$this->date($b['started_at']), $b['id']] <=> [$this->date($a['started_at']), $a['id']]);
            $pair = array_map(fn (array $run) => array_diff_key($run, ['id' => true]), $pair);
        }

        return $runs;
    }

    protected function runsOf(string $task): Builder
    {
        return $this->spyglass->connection()->table(ScheduleModule::RUNS)
            ->where('task', $task)
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function status(array $run, bool $superseded): string
    {
        $interruptedAfter = (int) config('spyglass.modules.schedule.interrupted_after', 86400);

        return $run['status'] === 'running'
            && ($superseded || $this->date($run['started_at'])->lt(Date::now()->subSeconds($interruptedAfter)))
            ? 'interrupted'
            : $run['status'];
    }

    /**
     * The nearest next run among the schedules of the task.
     *
     * @param  array<string, mixed>  $task
     */
    protected function nextRun(array $task): ?CarbonInterface
    {
        $next = null;

        foreach ($task['schedules'] as $schedule) {
            try {
                $run = $this->nextRunOf($schedule);
            } catch (Throwable $e) {
                $this->spyglass->report("Spyglass failed to compute the next run of [{$task['label']}]", $e);

                continue;
            }

            $next = $next === null || $run->lt($next) ? $run : $next;
        }

        return $next;
    }

    /**
     * @param  array{expression: string, timezone: string, repeat_seconds: ?int}  $schedule
     */
    protected function nextRunOf(array $schedule): CarbonInterface
    {
        $cron = new CronExpression($schedule['expression']);
        $now = Date::now();

        // Sub-minute tasks repeat from the start of every due minute.
        if ($repeat = $schedule['repeat_seconds']) {
            $minute = $now->copy()->setTimezone($schedule['timezone'])->startOfMinute();
            $next = $minute->copy()->addSeconds((intdiv((int) $minute->diffInSeconds($now), $repeat) + 1) * $repeat);

            if ($cron->isDue($minute, $schedule['timezone']) && $next->lt($minute->copy()->addMinute())) {
                return $next;
            }
        }

        return $this->existing($cron, $schedule['timezone'], fn (int $nth) => $cron->getNextRunDate($now, $nth, false, $schedule['timezone']));
    }

    /**
     * @param  array<string, mixed>  $task
     */
    protected function missed(array $task): bool
    {
        $due = Date::now()->subSeconds((int) config('spyglass.modules.schedule.missed_after', 90));
        $slot = null;

        // The latest due slot among the schedules.
        foreach ($task['schedules'] as $schedule) {
            try {
                $cron = new CronExpression($schedule['expression']);
                $latest = $this->existing($cron, $schedule['timezone'], fn (int $nth) => $cron->getPreviousRunDate($due, $nth, true, $schedule['timezone']));
            } catch (Throwable $e) {
                $this->spyglass->report("Spyglass failed to compute the due time of [{$task['label']}]", $e);

                continue;
            }

            if ($repeat = $schedule['repeat_seconds']) {
                $latest = $latest->addSeconds(min(intdiv((int) $latest->diffInSeconds($due), $repeat), intdiv(59, $repeat)) * $repeat);
            }

            $slot = $slot === null || $latest->gt($slot) ? $latest : $slot;
        }

        if ($slot === null) {
            return false;
        }

        $satisfied = array_filter([$task['last_run']['started_at'] ?? null, $task['last_skipped_at']]);

        return $slot->gt(max([$this->date($task['first_seen_at']), ...array_map($this->date(...), $satisfied)]));
    }

    /**
     * The first of the slots `$nth` returns that Laravel runs: a slot in the hour skipped by a spring-forward
     * night lands on a later wall-clock time, where Laravel, matching the wall clock, does not run the task.
     *
     * @param  Closure(int): DateTimeInterface  $nth
     */
    protected function existing(CronExpression $cron, string $timezone, Closure $nth): CarbonInterface
    {
        // A skipped hour holds at most 60 slots.
        for ($i = 0; $i <= 60; $i++) {
            $slot = Date::instance($nth($i));

            if ($cron->isDue($slot->copy()->setTimezone($timezone)->toDateTimeString())) {
                return $slot;
            }
        }

        return $slot;
    }

    protected function date(string $value): CarbonInterface
    {
        return Date::parse($value, 'UTC');
    }
}
