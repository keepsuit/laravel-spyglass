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

    /** A task the scheduler stopped publishing this long before the newest publish was removed from the schedule. */
    protected const int STALE_AFTER = 300;

    public function __construct(protected Spyglass $spyglass) {}

    /**
     * Tasks published by the scheduler with their next run, last run and whether a due run is missing.
     * A task is missed when its latest slot older than the grace period has neither a run nor a skip
     * since, and passed after the task was first seen. Stale tasks are never missed.
     *
     * Each task has the columns of its row, `environments` decoded, and `stale`, `next_run` (null when
     * stale), `last_run` and `missed`.
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
            ->orderBy('key')
            ->limit(self::MAX_TASKS)
            ->get();

        $lastRuns = $this->lastRuns($tasks->pluck('key')->all());
        $newest = is_string($tasks->first()?->newest) ? $this->date($tasks->first()->newest) : Date::now();

        return $tasks->map(fn (object $task) => $this->task($task, $lastRuns[$task->key][0] ?? null, $lastRuns[$task->key][1] ?? null, $newest));
    }

    /**
     * @param  array<string, mixed>|null  $lastRun
     * @param  array<string, mixed>|null  $previousRun
     * @return array<string, mixed>
     */
    protected function task(stdClass $task, ?array $lastRun, ?array $previousRun, CarbonInterface $newest): array
    {
        $overlapping = $lastRun !== null && $this->overlapped($lastRun, $previousRun);

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
            'command' => $task->command,
            'description' => $task->description,
            'expression' => $task->expression,
            'repeat_seconds' => $task->repeat_seconds === null ? null : (int) $task->repeat_seconds,
            'timezone' => $task->timezone,
            'environments' => $task->environments === null ? [] : (array) json_decode($task->environments, true),
            'without_overlapping' => (bool) $task->without_overlapping,
            'first_seen_at' => $task->first_seen_at,
            'last_seen_at' => $task->last_seen_at,
            'last_skipped_at' => $task->last_skipped_at,
            'last_skip_reason' => $task->last_skip_reason,
            'stale' => $stale,
            'last_run' => $lastRun,
            'overlapping' => $overlapping,
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
            $run->overlapped = $this->overlapped((array) $run, isset($rows[$i + 1]) ? (array) $rows[$i + 1] : null);
            $run->status = $this->status((array) $run, superseded: (bool) $run->without_overlapping && ($page > 1 || $i > 0));
            unset($run->without_overlapping);
            $runs[] = $run;
        }

        // The paginator tells there are more pages from an item beyond `$perPage`, which it leaves out.
        return new SimplePaginator(count($rows) > $perPage ? [...$runs, $rows[$perPage]] : $runs, $perPage, $page);
    }

    /**
     * Whether `$run` started while `$previous`, the run before it, was still executing. A run that never
     * finished counts until `interrupted_after`.
     *
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>|null  $previous
     */
    protected function overlapped(array $run, ?array $previous): bool
    {
        if ($previous === null) {
            return false;
        }

        $started = $this->date($run['started_at']);

        if ($previous['finished_at'] !== null) {
            return $this->date($previous['finished_at'])->gt($started);
        }

        return $previous['status'] === 'running'
            && $this->date($previous['started_at'])->gt($started->copy()->subSeconds((int) config('spyglass.modules.schedule.interrupted_after', 86400)));
    }

    /**
     * The two newest runs of each task, newest first: one `LIMIT 2` index lookup per task, in a single query per chunk.
     *
     * @param  array<mixed>  $keys
     * @return array<string, list<array<string, mixed>>>
     */
    protected function lastRuns(array $keys): array
    {
        $runs = [];

        foreach (array_chunk($keys, self::LAST_RUNS_CHUNK) as $chunk) {
            $query = null;

            foreach ($chunk as $key) {
                $last = $this->runsOf($key)
                    ->select(['id', 'task', 'status', 'started_at', 'finished_at', 'duration_ms', 'exit_code'])
                    ->limit(2);
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
     * @param  array<string, mixed>  $task
     */
    protected function nextRun(array $task): ?CarbonInterface
    {
        try {
            $cron = new CronExpression($task['expression']);
            $now = Date::now();

            // Sub-minute tasks repeat from the start of every due minute.
            if ($repeat = $task['repeat_seconds']) {
                $minute = $now->copy()->setTimezone($task['timezone'])->startOfMinute();
                $next = $minute->copy()->addSeconds((intdiv((int) $minute->diffInSeconds($now), $repeat) + 1) * $repeat);

                if ($cron->isDue($minute, $task['timezone']) && $next->lt($minute->copy()->addMinute())) {
                    return $next;
                }
            }

            return $this->existing($cron, $task['timezone'], fn (int $nth) => $cron->getNextRunDate($now, $nth, false, $task['timezone']));
        } catch (Throwable $e) {
            $this->spyglass->report("Spyglass failed to compute the next run of [{$task['label']}]", $e);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    protected function missed(array $task): bool
    {
        // Such tasks never fire any event, so they have no record to compare with.
        if ($task['environments'] !== [] && ! in_array(app()->environment(), $task['environments'], true)) {
            return false;
        }

        try {
            $due = Date::now()->subSeconds((int) config('spyglass.modules.schedule.missed_after', 90));
            $cron = new CronExpression($task['expression']);
            $slot = $this->existing($cron, $task['timezone'], fn (int $nth) => $cron->getPreviousRunDate($due, $nth, true, $task['timezone']));
        } catch (Throwable $e) {
            $this->spyglass->report("Spyglass failed to compute the due time of [{$task['label']}]", $e);

            return false;
        }

        if ($repeat = $task['repeat_seconds']) {
            $slot->addSeconds(min(intdiv((int) $slot->diffInSeconds($due), $repeat), intdiv(59, $repeat)) * $repeat);
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
        for ($i = 0; $i < 3; $i++) {
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
