<?php

namespace Keepsuit\Spyglass\Modules\Schedule;

use Carbon\CarbonInterface;
use Closure;
use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Query\Builder;
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

        return $tasks->map(fn (object $task) => $this->task($task, $lastRuns[$task->key] ?? null, $newest));
    }

    /**
     * @param  array<string, mixed>|null  $lastRun
     * @return array<string, mixed>
     */
    protected function task(stdClass $task, ?array $lastRun, CarbonInterface $newest): array
    {
        $stale = $this->date($task->last_seen_at)->lt($newest->copy()->subSeconds(self::STALE_AFTER));

        if ($lastRun !== null) {
            $lastRun['status'] = $this->status($lastRun, superseded: false);
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
            'first_seen_at' => $task->first_seen_at,
            'last_seen_at' => $task->last_seen_at,
            'last_skipped_at' => $task->last_skipped_at,
            'last_skip_reason' => $task->last_skip_reason,
            'stale' => $stale,
            'last_run' => $lastRun,
        ];

        return [...$task, 'next_run' => $stale ? null : $this->nextRun($task), 'missed' => ! $stale && $this->missed($task)];
    }

    /**
     * Runs of a task, newest first. A run still `running` is `interrupted` when a later one started or
     * when it is older than `interrupted_after`.
     *
     * @return Paginator<int, object>
     */
    public function runs(string $task, int $perPage = 25, int $page = 1): Paginator
    {
        $runs = $this->runsOf($task)->simplePaginate(max(1, min($perPage, 100)), page: $page);

        foreach ($runs->items() as $i => $run) {
            $run->status = $this->status((array) $run, superseded: $page > 1 || $i > 0);
        }

        return $runs;
    }

    /**
     * The newest run of each task: one `LIMIT 1` index lookup per task, in a single query per chunk.
     *
     * @param  array<mixed>  $keys
     * @return array<string, array<string, mixed>>
     */
    protected function lastRuns(array $keys): array
    {
        $runs = [];

        foreach (array_chunk($keys, self::LAST_RUNS_CHUNK) as $chunk) {
            $query = null;

            foreach ($chunk as $key) {
                $last = $this->runsOf($key)
                    ->select(['task', 'status', 'started_at', 'finished_at', 'duration_ms', 'exit_code'])
                    ->limit(1);
                $query = $query ? $query->unionAll($last) : $last;
            }

            foreach ($query->get() as $run) {
                $runs[$run->task] = (array) $run;
            }
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
