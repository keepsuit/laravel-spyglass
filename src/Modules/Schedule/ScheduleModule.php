<?php

namespace Keepsuit\Spyglass\Modules\Schedule;

use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as Task;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Module;
use Keepsuit\Spyglass\Support\ExceptionFingerprint;
use Keepsuit\Spyglass\Support\Sources;
use ReflectionFunction;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * The scheduler publishes its task list at the end of every `schedule:run` and records one row per execution, keyed by
 * an id generated when the task starts: every write of a run is an upsert on it, so the buffered `running`
 * row (flushed at `ScheduledTaskStarting`) and its closing write are the same row. Skips are not runs: they
 * only update the task row. Neither are successful runs of sub-minute tasks: up to 86,400 a day, so only
 * the last one is kept on the task row, and only failed ones become rows.
 */
class ScheduleModule extends Module
{
    public const string TASKS = 'spyglass_schedule_tasks';

    public const string RUNS = 'spyglass_schedule_runs';

    public const int OUTPUT_BYTES = 10240;

    /** Hidden Context key carrying the run id to the `schedule:finish` process of a background task. */
    public const string CONTEXT_RUN = 'spyglass_schedule_run';

    /** Sub-minute tasks are skipped or run every few seconds: one write per task within this many seconds. */
    protected const int TASK_WRITE_INTERVAL = 30;

    /**
     * Runs started by this process, by run id. Not by task: Laravel reuses the task object for every
     * execution of a sub-minute task.
     *
     * @var array<string, array{row: array<string, mixed>, started: int|float, closed: bool, quiet: bool}>
     */
    protected array $runs = [];

    /** @var WeakMap<Task, string> latest run id of each task */
    protected WeakMap $current;

    /** @var WeakMap<Task, array{key: string, label: string}> */
    protected WeakMap $identities;

    /** @var array<string, array{reason: ?string, at: int}> last skip written per task key */
    protected array $skips = [];

    /** @var array<string, array{last_skipped_at: string, last_skip_reason: ?string}> last skip of each task key in this tick */
    protected array $lastSkips = [];

    /** @var array<string, int> when the last successful run of a sub-minute task was written, per task key */
    protected array $runWrites = [];

    /** @var array<string, array{last_run_at: string, last_duration_ms: int}> last successful sub-minute run of each task key in this tick */
    protected array $lastRuns = [];

    /** When the current `schedule:run` started: the time its publication reports. */
    protected ?CarbonInterface $tick = null;

    public function __construct()
    {
        $this->current = new WeakMap;
        $this->identities = new WeakMap;
    }

    public function boot(): void
    {
        // `schedule:work` runs `schedule:run` every minute, so this covers both.
        $this->on(CommandStarting::class, function (CommandStarting $event) {
            if ($event->command === 'schedule:run') {
                $this->tick = Date::now();
            }
        });

        // After the due tasks ran: a slow database must never delay their evaluation.
        $this->on(CommandFinished::class, function (CommandFinished $event) {
            if ($event->command === 'schedule:run' && $this->tick !== null) {
                $this->publish($this->tick);
                $this->tick = null;
            }
        });

        $this->on(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $event) => $this->start($event->task));

        $this->on(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            $task = $event->task;
            $id = $this->current[$task] ?? null;

            // The process got it already.
            Context::forgetHidden(Sources::CONTEXT_SOURCE);

            if ($task->runInBackground) {
                Context::forgetHidden(self::CONTEXT_RUN);
            }

            // Laravel throws on a non-zero exit code right after this event: the exception keeps the task as its source.
            if ($task->exitCode == 0 || $task->runInBackground) {
                app(Sources::class)->leaveTask();
            }

            if ($id === null || $this->runs[$id]['closed']) {
                return;
            }

            // The flag is missing on Laravel 12, where an overlap skip only leaves the exit
            // code null. A failed earlier execution of a sub-minute task leaves it set, so that case is lost.
            if (data_get($task, 'skippedBecauseOverlapping', false) || ($task->exitCode === null && ! $task->runInBackground)) {
                // Laravel fires `ScheduledTaskFailed` next when a stale exit code is not 0: it must not reopen the run.
                $this->runs[$id]['closed'] = true;

                if (! $this->runs[$id]['quiet']) {
                    $this->deleteRun($id);
                }

                $this->skip($task, 'overlapping');
            } elseif (! $task->runInBackground) {
                $this->close($id, $task->exitCode === 0 ? 'success' : 'failed', $task->exitCode, $this->output($task, $this->runs[$id]['row']['output_offset']));
            }
            // Background tasks are still running: `schedule:finish` reports their outcome.
        });

        $this->on(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            $task = $event->task;
            $id = $this->current[$task] ?? null;

            Context::forgetHidden(Sources::CONTEXT_SOURCE);

            if ($task->runInBackground) {
                Context::forgetHidden(self::CONTEXT_RUN);
            }

            // Laravel reports the exception after this event.
            app(Sources::class)->leaveTask($event->exception);

            if ($id !== null && ! $this->runs[$id]['closed']) {
                $this->close($id, 'failed', $task->exitCode, $this->output($task, $this->runs[$id]['row']['output_offset']), $event->exception);
            }
        });

        $this->on(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) {
            // Missing on Laravel 12.
            $reason = data_get($event, 'reason');

            $this->skip($event->task, $reason instanceof BackedEnum ? (string) $reason->value : null);
        });

        // Fired by `schedule:finish` in a process of its own.
        $this->on(ScheduledBackgroundTaskFinished::class, fn (ScheduledBackgroundTaskFinished $event) => $this->finishInBackground($event->task));
    }

    public function prunable(): array
    {
        return [
            self::TASKS => ['column' => 'last_seen_at', 'retention' => 'runs'],
            self::RUNS => ['column' => 'started_at', 'retention' => 'runs'],
        ];
    }

    /**
     * Keep the newest runs of each task by outcome: a task that runs every minute would otherwise keep
     * 43,200 runs over the retention.
     */
    public function prune(): array
    {
        $db = Spyglass::connection();
        $deleted = 0;
        $keep = [
            'successful_runs_per_task' => fn (Builder $query) => $query->where('status', 'success'),
            'failed_runs_per_task' => fn (Builder $query) => $query->where('status', '!=', 'success'),
        ];

        foreach ($db->table(self::RUNS)->distinct()->pluck('task') as $task) {
            foreach ($keep as $config => $outcome) {
                $limit = config("spyglass.modules.schedule.retention.{$config}");

                if (! is_numeric($limit)) {
                    continue;
                }

                // Short batches keep locks and the transaction log small on large tables.
                do {
                    $ids = $db->table(self::RUNS)->where('task', $task)->where($outcome)
                        ->orderByDesc('started_at')->orderByDesc('id')
                        ->offset(max(0, (int) $limit))->limit(1000)
                        ->pluck('id');

                    $deleted += $ids->isEmpty() ? 0 : $db->table(self::RUNS)->whereIn('id', $ids)->delete();
                } while ($ids->count() === 1000);
            }
        }

        return [self::RUNS => $deleted];
    }

    /**
     * Stable identities, aligned with `$tasks`. The key is a digest of the command or the description, never
     * of the cron expression, so the history survives a frequency change, and of the position among tasks
     * with the same identity. Closures without a description use `file:line`.
     *
     * @param  array<array-key, Task>  $tasks
     * @return list<array{key: string, label: string}>
     */
    public static function identities(array $tasks): array
    {
        $seen = [];

        return array_map(function (Task $task) use (&$seen) {
            $identity = static::identity($task);
            $ordinal = $seen[$identity] = ($seen[$identity] ?? 0) + 1;

            return [
                'key' => sha1($ordinal.':'.$identity),
                'label' => mb_substr($identity, 0, 240).($ordinal > 1 ? " #{$ordinal}" : ''),
            ];
        }, array_values($tasks));
    }

    public static function timezone(Task $task): string
    {
        // Laravel evaluates a task without a timezone in the PHP default one, which is the application's.
        return $task->timezone instanceof DateTimeZone ? $task->timezone->getName() : ($task->timezone ?: date_default_timezone_get());
    }

    protected static function identity(Task $task): string
    {
        return match (true) {
            // Normalized: the command embeds the absolute PHP binary, which differs between hosts and upgrades.
            is_string($task->command) && $task->command !== '' => Task::normalizeCommand($task->command),
            is_string($task->description) && $task->description !== '' => $task->description,
            $task instanceof CallbackEvent => static::callbackName((fn () => $this->callback)->call($task)),
            default => $task->getSummaryForDisplay(),
        };
    }

    protected static function callbackName(mixed $callback): string
    {
        if ($callback instanceof Closure) {
            $function = new ReflectionFunction($callback);
            $file = (string) $function->getFileName();

            // Both, for deploys where the base path is a symlink to the release.
            foreach (array_unique([base_path(), realpath(base_path())]) as $base) {
                if ($base && str_starts_with($file, $base.DIRECTORY_SEPARATOR)) {
                    $file = substr($file, strlen($base) + 1);
                    break;
                }
            }

            return $file.':'.$function->getStartLine();
        }

        return match (true) {
            is_string($callback) => $callback,
            is_array($callback) => (is_object($callback[0]) ? $callback[0]::class : $callback[0]).'@'.$callback[1],
            is_object($callback) => $callback::class,
            default => 'Callback',
        };
    }

    /**
     * Upsert every task of the schedule as seen at `$tick`, keeping `first_seen_at`, with the last skip of
     * each task skipped in this tick: one statement for the skipped tasks and one for the others.
     */
    protected function publish(CarbonInterface $tick): void
    {
        $this->guard('Spyglass failed to publish the schedule', function () use ($tick) {
            $tasks = array_values(app(Schedule::class)->events());
            $seen = $this->stamp($tick);

            // Laravel leaves due tasks out silently while the application is down.
            if (app()->isDownForMaintenance()) {
                foreach ($tasks as $task) {
                    if (! $task->runsInMaintenanceMode() && $task->runsInEnvironment(app()->environment())
                        && (new CronExpression($task->getExpression()))->isDue($tick, static::timezone($task))) {
                        $this->lastSkips[$this->identityOf($task)['key']] = ['last_skipped_at' => $seen, 'last_skip_reason' => 'maintenance'];
                    }
                }
            }

            $rows = [];

            foreach ($tasks as $task) {
                $identity = $this->identityOf($task);
                $rows[$identity['key']] = [
                    ...$identity,
                    'command' => is_string($task->command) ? Task::normalizeCommand($task->command) : null,
                    'description' => is_string($task->description) ? $task->description : null,
                    'expression' => $task->getExpression(),
                    'repeat_seconds' => $task->repeatSeconds,
                    'timezone' => static::timezone($task),
                    'environments' => $task->environments === [] ? null : json_encode(array_values($task->environments)),
                    'without_overlapping' => $task->withoutOverlapping,
                    'first_seen_at' => $seen,
                    'last_seen_at' => $seen,
                    ...($this->lastSkips[$identity['key']] ?? []),
                    ...($this->lastRuns[$identity['key']] ?? []),
                ];
            }

            // The same order in every process, so concurrent hosts lock rows in the same sequence.
            ksort($rows, SORT_STRING);
            $update = ['label', 'command', 'description', 'expression', 'repeat_seconds', 'timezone', 'environments', 'without_overlapping', 'last_seen_at'];

            // One statement per column shape: tasks skipped or run in this tick carry those columns too.
            $groups = [];

            foreach ($rows as $row) {
                $groups[implode(',', array_keys($row))][] = $row;
            }

            foreach ($groups as $group) {
                $columns = [...$update, ...array_diff(array_keys($group[0]), [...$update, 'key', 'first_seen_at'])];

                foreach (array_chunk($group, 80) as $chunk) {
                    Spyglass::connection()->table(self::TASKS)->upsert($chunk, ['key'], $columns);
                }
            }

            $this->lastSkips = [];
            $this->lastRuns = [];
        });
    }

    protected function start(Task $task): void
    {
        if (isset($this->current[$task])) {
            unset($this->runs[$this->current[$task]]);
        }

        // On its own: a failure leaves the run without output instead of unrecorded.
        $offset = null;
        $this->safely('Spyglass failed to read the output size of a scheduled task', function () use ($task, &$offset) {
            $offset = $this->outputOffset($task);
        });

        $id = (string) Str::ulid();
        $row = [
            'task' => $this->identityOf($task)['key'],
            'started_at' => $this->now(),
            'finished_at' => null,
            'duration_ms' => null,
            'status' => 'running',
            'exit_code' => null,
            'output' => null,
            'exception_class' => null,
            'fingerprint' => null,
            'output_offset' => $offset,
            'host' => $this->host(),
            ...Spyglass::captureContext(),
        ];

        // A background run needs its row: `schedule:finish` closes it in another process.
        $quiet = $task->isRepeatable() && ! $task->runInBackground;

        $this->current[$task] = $id;
        $this->runs[$id] = ['row' => $row, 'started' => hrtime(true), 'closed' => false, 'quiet' => $quiet];

        if (! $quiet) {
            Spyglass::upsert(self::RUNS, ['id' => $id], $row);
        }

        $label = $this->identityOf($task)['label'];
        app(Sources::class)->enterTask($label, $id);

        // Laravel passes the Context to the process of a command, in the foreground too.
        if (! $task instanceof CallbackEvent) {
            Context::addHidden(Sources::CONTEXT_SOURCE, ['source' => $label, 'source_id' => $id]);
        }

        // `schedule:finish` gets it back.
        if ($task->runInBackground) {
            Context::addHidden(self::CONTEXT_RUN, $id);
        }
    }

    protected function close(string $id, string $status, ?int $exitCode, ?string $output, ?Throwable $exception = null): void
    {
        $run = &$this->runs[$id];
        $run['closed'] = true;
        $duration = (int) round((hrtime(true) - $run['started']) / 1e6);

        if ($run['quiet'] && $status === 'success') {
            $this->recordLastRun($run['row']['task'], $run['row']['started_at'], $duration);

            return;
        }

        // The whole row: the insert half of an upsert must satisfy NOT NULL columns.
        Spyglass::upsert(self::RUNS, ['id' => $id], [
            ...$run['row'],
            'finished_at' => $this->now(),
            'duration_ms' => $duration,
            'status' => $status,
            'exit_code' => $exitCode,
            'output' => $output,
            'exception_class' => $exception === null ? null : mb_substr($exception::class, 0, 255),
            'fingerprint' => $exception === null ? null : ExceptionFingerprint::for($exception),
        ]);
    }

    /**
     * Close the run that the background process was started for, whose id Laravel passes on in the Context.
     */
    protected function finishInBackground(Task $task): void
    {
        $this->guard('Spyglass failed to close a background run', function () use ($task) {
            $id = Context::getHidden(self::CONTEXT_RUN);

            if (! is_string($id)) {
                return;
            }

            // A replica may not have the start of the run yet.
            $runs = fn () => Spyglass::connection()->table(self::RUNS)->useWritePdo()->where('status', 'running')->where('id', $id);
            $run = $runs()->first();

            if ($run === null) {
                return;
            }

            $run = (array) $run;
            $runs()->update([
                'finished_at' => $this->now(),
                'duration_ms' => max(0, (int) Date::parse($run['started_at'], 'UTC')->diffInMilliseconds(Date::now())),
                'status' => $task->exitCode === 0 ? 'success' : 'failed',
                'exit_code' => $task->exitCode,
                'output' => $this->output($task, $run['output_offset'] === null ? null : (int) $run['output_offset']),
            ]);
        });
    }

    /**
     * Record the last successful run of a sub-minute task on its row. The tick publication writes the last one in any case.
     */
    protected function recordLastRun(string $key, string $startedAt, int $duration): void
    {
        $this->lastRuns[$key] = ['last_run_at' => $startedAt, 'last_duration_ms' => $duration];
        $now = Date::now()->getTimestamp();

        if ($now - ($this->runWrites[$key] ?? PHP_INT_MIN) < self::TASK_WRITE_INTERVAL) {
            return;
        }

        $this->guard('Spyglass failed to record the last run of a scheduled task', function () use ($key, $now) {
            Spyglass::connection()->table(self::TASKS)->where('key', $key)->update($this->lastRuns[$key]);
            $this->runWrites[$key] = $now;
        });
    }

    protected function deleteRun(string $id): void
    {
        $this->guard('Spyglass failed to delete a skipped run', fn () => Spyglass::connection()->table(self::RUNS)->where('id', $id)->delete());
    }

    /**
     * Record a skip on the task row. The tick publication writes the last one in any case.
     */
    protected function skip(Task $task, ?string $reason): void
    {
        $this->guard('Spyglass failed to record a skipped task', function () use ($task, $reason) {
            // Laravel reports an overlap caught before the run as an unmet condition.
            if (in_array($reason, [null, 'condition_not_met'], true) && $task->withoutOverlapping && $task->mutex->exists($task)) {
                $reason = 'overlapping';
            }

            $key = $this->identityOf($task)['key'];
            $now = Date::now()->getTimestamp();
            $last = $this->skips[$key] ?? null;
            $this->lastSkips[$key] = ['last_skipped_at' => $this->now(), 'last_skip_reason' => $reason];

            if ($last !== null && $last['reason'] === $reason && $now - $last['at'] < self::TASK_WRITE_INTERVAL) {
                return;
            }

            Spyglass::connection()->table(self::TASKS)->where('key', $key)->update($this->lastSkips[$key]);
            $this->skips[$key] = ['reason' => $reason, 'at' => $now];
        });
    }

    /**
     * @return array{key: string, label: string}
     */
    protected function identityOf(Task $task): array
    {
        if (! isset($this->identities[$task])) {
            // Ordinals depend on the whole schedule. Tasks are only ever appended, so earlier ones keep theirs.
            $tasks = array_values(app(Schedule::class)->events());

            foreach (static::identities($tasks) as $i => $identity) {
                $this->identities[$tasks[$i]] = $identity;
            }
        }

        return $this->identities[$task] ?? static::identities([$task])[0];
    }

    /**
     * A listener whose failures are reported, never thrown into the scheduler or the task.
     *
     * @param  class-string  $event
     */
    protected function on(string $event, Closure $listener): void
    {
        Event::listen($event, fn (object $payload) => $this->safely('Spyglass failed to record the schedule', fn () => $listener($payload)));
    }

    /**
     * Direct writes, not recorded by Spyglass. Buffered writes are dropped inside: use `safely()` for them.
     *
     * @param  Closure(): mixed  $callback
     */
    protected function guard(string $message, Closure $callback): void
    {
        Spyglass::ignore(fn () => $this->safely($message, $callback));
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    protected function safely(string $message, Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Spyglass::report($message, $e);
        }
    }

    protected function outputPath(Task $task): ?string
    {
        if ($task instanceof CallbackEvent || ! $task->output || $task->output === $task->getDefaultOutput()) {
            return null;
        }

        // Laravel runs the command from the base path.
        return str_starts_with($task->output, '/') || preg_match('~^[a-zA-Z]:[\\\\/]~', $task->output) === 1
            ? $task->output
            : base_path($task->output);
    }

    protected function outputOffset(Task $task): ?int
    {
        $path = $this->outputPath($task);

        if ($path === null || ! $task->shouldAppendOutput) {
            return null;
        }

        clearstatcache(true, $path);

        // Not null: in append mode that means the offset is unknown.
        if (! is_file($path)) {
            return 0;
        }

        $size = filesize($path);

        return $size !== false ? $size : throw new RuntimeException("Cannot read the size of [{$path}]");
    }

    /**
     * What the run wrote to the output file, only when the task already writes one: its last bytes when longer.
     */
    protected function output(Task $task, ?int $offset): ?string
    {
        $path = $this->outputPath($task);

        // An appended file without the offset would show the output of earlier runs.
        if ($path === null || ($offset === null && $task->shouldAppendOutput) || ! is_file($path)) {
            return null;
        }

        clearstatcache(true, $path);
        $size = (int) filesize($path);
        // A smaller file was truncated or rotated since the run started.
        $start = max($offset !== null && $offset <= $size ? $offset : 0, $size - self::OUTPUT_BYTES);

        $output = @file_get_contents($path, false, null, $start, self::OUTPUT_BYTES);

        if ($output === false) {
            Spyglass::report('Spyglass failed to read the output of a scheduled task', new RuntimeException(error_get_last()['message'] ?? "Cannot read [{$path}]"));

            return null;
        }

        // The cut can split a character, and PostgreSQL rejects invalid UTF-8 and NUL bytes.
        return str_replace("\0", '', mb_scrub($output, 'UTF-8'));
    }

    protected function host(): string
    {
        return mb_substr((string) gethostname(), 0, 191);
    }

    protected function now(): string
    {
        return $this->stamp(Date::now());
    }

    protected function stamp(CarbonInterface $time): string
    {
        return $time->copy()->utc()->format('Y-m-d H:i:s.v');
    }
}
