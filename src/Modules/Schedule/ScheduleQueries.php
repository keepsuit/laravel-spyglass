<?php

namespace Keepsuit\Spyglass\Modules\Schedule;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event as Task;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Keepsuit\Spyglass\Spyglass;

/**
 * Read side of the schedule module. Every query is bounded.
 */
class ScheduleQueries
{
    /** Tasks per last-run query: SQLite allows at most 500 terms in a compound select. */
    protected const int LAST_RUNS_CHUNK = 100;

    public function __construct(protected Spyglass $spyglass) {}

    /**
     * Tasks of the console schedule with their next run, last record and whether a due run is missing.
     * A task is missed when no run started since its last due time older than the grace period, unless
     * its last record is a skip. A task that never ran is missed too.
     *
     * @return Collection<int, array{key: string, command: ?string, description: ?string, expression: string, timezone: ?string, next_run: Carbon, last_run: ?array<string, mixed>, missed: bool}>
     */
    public function tasks(): Collection
    {
        $tasks = array_values($this->schedule()->events());
        $keys = ScheduleModule::keys($tasks);
        $lastRuns = $this->lastRuns($keys);
        $grace = (int) config('spyglass.modules.schedule.missed_after', 90);

        return collect($tasks)->map(fn (Task $task, int $i) => [
            'key' => $keys[$i],
            'command' => is_string($task->command) ? Task::normalizeCommand($task->command) : null,
            'description' => is_string($task->description) ? $task->description : null,
            'expression' => $task->getExpression(),
            'timezone' => ScheduleModule::timezone($task),
            'next_run' => $task->nextRunDate(Date::now()),
            'last_run' => $lastRuns[$keys[$i]] ?? null,
            'missed' => $this->missed($task, $lastRuns[$keys[$i]] ?? null, $grace),
        ]);
    }

    /**
     * Runs of a task, newest first.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function runs(string $task, int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        return $this->runsOf($task)->paginate(max(1, min($perPage, 100)), page: $page);
    }

    /**
     * A web request skips `routes/console.php` and `withSchedule()`: they run when Artisan boots.
     * `output()` boots it without resolving the lazy commands, as `all()` would.
     */
    protected function schedule(): Schedule
    {
        app(ConsoleKernel::class)->output();

        return app(Schedule::class);
    }

    /**
     * The newest record of each task: one `LIMIT 1` index lookup per task, in a single query per chunk.
     *
     * @param  list<string>  $keys
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
     * @param  array<string, mixed>|null  $lastRun
     */
    protected function missed(Task $task, ?array $lastRun, int $grace): bool
    {
        // Such tasks never fire any event, so they have no record to compare with.
        if (! $task->runsInEnvironment(app()->environment()) || ($lastRun['status'] ?? null) === 'skipped') {
            return false;
        }

        $due = (new CronExpression($task->getExpression()))
            ->getPreviousRunDate(Date::now()->subSeconds($grace), 0, true, ScheduleModule::timezone($task));

        return $lastRun === null || Date::parse($lastRun['started_at'])->lt($due);
    }
}
