<?php

namespace Keepsuit\Spyglass\Modules\Schedule;

use BackedEnum;
use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as Task;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Module;
use ReflectionFunction;
use Throwable;
use WeakMap;

/**
 * The scheduler publishes its task list on every `schedule:run` and records one row per execution, keyed by
 * an id generated when the task starts: every write of a run is an upsert on it, so the buffered `running`
 * row (flushed at `ScheduledTaskStarting`) and its closing write are the same row. Skips are not runs: they
 * only update the task row.
 */
class ScheduleModule extends Module
{
    public const string TASKS = 'spyglass_schedule_tasks';

    public const string RUNS = 'spyglass_schedule_runs';

    public const int OUTPUT_BYTES = 10240;

    /** Hidden Context key carrying the run id to the `schedule:finish` process of a background task. */
    public const string CONTEXT_RUN = 'spyglass_schedule_run';

    /** Sub-minute tasks are skipped every few seconds: one write per task within this many seconds. */
    protected const int SKIP_WRITE_INTERVAL = 30;

    /**
     * Runs started by this process, by run id. Not by task: Laravel reuses the task object for every
     * execution of a sub-minute task.
     *
     * @var array<string, array{row: array<string, mixed>, started: int|float, closed: bool}>
     */
    protected array $runs = [];

    /** @var WeakMap<Task, string> latest run id of each task */
    protected WeakMap $current;

    /** @var WeakMap<Task, array{key: string, label: string}> */
    protected WeakMap $identities;

    /** @var array<string, array{reason: ?string, at: int}> last skip written per task key */
    protected array $skips = [];

    public function __construct()
    {
        $this->current = new WeakMap;
        $this->identities = new WeakMap;
    }

    public function boot(): void
    {
        // `schedule:work` runs `schedule:run` every minute, so this covers both.
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if ($event->command === 'schedule:run') {
                $this->publish();
            }
        });

        Event::listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $event) => $this->start($event->task));

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            $task = $event->task;
            $id = $this->current[$task] ?? null;

            if ($task->runInBackground) {
                Context::forgetHidden(self::CONTEXT_RUN);
            }

            if ($id === null || $this->runs[$id]['closed']) {
                return;
            }

            // The flag is missing on older Laravel 12 releases, where an overlap skip only leaves the exit
            // code null. A failed earlier execution of a sub-minute task leaves it set, so that case is lost.
            if (data_get($task, 'skippedBecauseOverlapping', false) || ($task->exitCode === null && ! $task->runInBackground)) {
                // Laravel fires `ScheduledTaskFailed` next when a stale exit code is not 0: it must not reopen the run.
                $this->runs[$id]['closed'] = true;
                $this->deleteRun($id);
                $this->skip($task, 'overlapping');
            } elseif (! $task->runInBackground) {
                $this->close($id, $task->exitCode === 0 ? 'success' : 'failed', $task->exitCode, $this->output($task, $this->runs[$id]['row']['output_offset']));
            }
            // Background tasks are still running: `schedule:finish` reports their outcome.
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            $task = $event->task;
            $id = $this->current[$task] ?? null;

            if ($task->runInBackground) {
                Context::forgetHidden(self::CONTEXT_RUN);
            }

            if ($id !== null && ! $this->runs[$id]['closed']) {
                $this->close($id, 'failed', $task->exitCode, $this->output($task, $this->runs[$id]['row']['output_offset']));
            }
        });

        Event::listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) {
            // Missing on older Laravel 12 releases.
            $reason = data_get($event, 'reason');

            $this->skip($event->task, $reason instanceof BackedEnum ? (string) $reason->value : null);
        });

        // Fired by `schedule:finish` in a process of its own.
        Event::listen(ScheduledBackgroundTaskFinished::class, fn (ScheduledBackgroundTaskFinished $event) => $this->finishInBackground($event->task));
    }

    public function prunable(): array
    {
        return [
            self::TASKS => ['column' => 'last_seen_at', 'retention' => 'runs'],
            self::RUNS => ['column' => 'started_at', 'retention' => 'runs'],
        ];
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
     * Upsert every task of the schedule in one statement, keeping `first_seen_at` and the last skip.
     */
    protected function publish(): void
    {
        $this->guard('Spyglass failed to publish the schedule', function () {
            $tasks = array_values(app(Schedule::class)->events());
            $now = $this->now();
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
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ];
            }

            // The same order in every process, so concurrent hosts lock rows in the same sequence.
            ksort($rows, SORT_STRING);
            $update = ['label', 'command', 'description', 'expression', 'repeat_seconds', 'timezone', 'environments', 'last_seen_at'];

            foreach (array_chunk($rows, 80) as $chunk) {
                Spyglass::connection()->table(self::TASKS)->upsert($chunk, ['key'], $update);
            }

            // Laravel leaves due tasks out silently while the application is down.
            if (app()->isDownForMaintenance()) {
                foreach ($tasks as $task) {
                    if (! $task->runsInMaintenanceMode() && $task->runsInEnvironment(app()->environment())
                        && (new CronExpression($task->getExpression()))->isDue(Date::now(), static::timezone($task))) {
                        $this->skip($task, 'maintenance');
                    }
                }
            }
        });
    }

    protected function start(Task $task): void
    {
        if (isset($this->current[$task])) {
            unset($this->runs[$this->current[$task]]);
        }

        $id = (string) Str::ulid();
        $row = [
            'task' => $this->identityOf($task)['key'],
            'started_at' => $this->now(),
            'finished_at' => null,
            'duration_ms' => null,
            'status' => 'running',
            'exit_code' => null,
            'output' => null,
            'output_offset' => $this->outputOffset($task),
            'host' => $this->host(),
            ...Spyglass::captureContext(),
        ];

        $this->current[$task] = $id;
        $this->runs[$id] = ['row' => $row, 'started' => hrtime(true), 'closed' => false];
        Spyglass::upsert(self::RUNS, ['id' => $id], $row);

        // Laravel passes the Context to the background process, and `schedule:finish` gets it back.
        if ($task->runInBackground) {
            Context::addHidden(self::CONTEXT_RUN, $id);
        }
    }

    protected function close(string $id, string $status, ?int $exitCode, ?string $output): void
    {
        $run = &$this->runs[$id];
        $run['closed'] = true;

        // The whole row: the insert half of an upsert must satisfy NOT NULL columns.
        Spyglass::upsert(self::RUNS, ['id' => $id], [
            ...$run['row'],
            'finished_at' => $this->now(),
            'duration_ms' => (int) round((hrtime(true) - $run['started']) / 1e6),
            'status' => $status,
            'exit_code' => $exitCode,
            'output' => $output,
        ]);
    }

    /**
     * Close the run that the background process was started for, or, when Laravel does not pass the Context
     * on, the newest one still open for the task on this host.
     */
    protected function finishInBackground(Task $task): void
    {
        $this->guard('Spyglass failed to close a background run', function () use ($task) {
            $id = Context::getHidden(self::CONTEXT_RUN);
            $runs = fn () => Spyglass::connection()->table(self::RUNS)->where('status', 'running');

            // Retried only without an id: another finisher may close the run between the lookup and the update.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $run = is_string($id)
                    ? $runs()->where('id', $id)->first()
                    : $runs()->where('task', $this->identityOf($task)['key'])->where('host', $this->host())
                        ->orderByDesc('started_at')->orderByDesc('id')->first();

                if ($run === null) {
                    return;
                }

                $closed = $runs()->where('id', $run->id)->update([
                    'finished_at' => $this->now(),
                    'duration_ms' => max(0, (int) Date::parse($run->started_at, 'UTC')->diffInMilliseconds(Date::now())),
                    'status' => $task->exitCode === 0 ? 'success' : 'failed',
                    'exit_code' => $task->exitCode,
                    'output' => $this->output($task, $run->output_offset === null ? null : (int) $run->output_offset),
                ]);

                if ($closed > 0 || is_string($id)) {
                    return;
                }
            }
        });
    }

    protected function deleteRun(string $id): void
    {
        $this->guard('Spyglass failed to delete a skipped run', fn () => Spyglass::connection()->table(self::RUNS)->where('id', $id)->delete());
    }

    /**
     * Record a skip on the task row.
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

            if ($last !== null && $last['reason'] === $reason && $now - $last['at'] < self::SKIP_WRITE_INTERVAL) {
                return;
            }

            $this->skips[$key] = ['reason' => $reason, 'at' => $now];

            Spyglass::connection()->table(self::TASKS)->where('key', $key)->update([
                'last_skipped_at' => $this->now(),
                'last_skip_reason' => $reason,
            ]);
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
     * @param  Closure(): mixed  $callback
     */
    protected function guard(string $message, Closure $callback): void
    {
        Spyglass::ignore(function () use ($message, $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                Spyglass::report($message, $e);
            }
        });
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

        return is_file($path) ? (int) filesize($path) : null;
    }

    /**
     * What the run wrote to the output file, only when the task already writes one: its last bytes when longer.
     */
    protected function output(Task $task, ?int $offset): ?string
    {
        $path = $this->outputPath($task);

        if ($path === null || ! is_file($path)) {
            return null;
        }

        clearstatcache(true, $path);
        $size = (int) filesize($path);
        // A smaller file was truncated or rotated since the run started.
        $start = max($offset !== null && $offset <= $size ? $offset : 0, $size - self::OUTPUT_BYTES);

        $output = @file_get_contents($path, false, null, $start, self::OUTPUT_BYTES);

        if ($output === false) {
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
        return Date::now('UTC')->format('Y-m-d H:i:s.v');
    }
}
