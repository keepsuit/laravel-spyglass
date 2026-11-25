<?php

namespace Keepsuit\Spyglass\Modules\Schedule;

use Closure;
use DateTimeZone;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as Task;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Module;
use ReflectionFunction;
use Throwable;
use WeakMap;

/**
 * One row per run, keyed by an id generated when the task starts: every write is an upsert on it, so the
 * buffered `running` row (flushed at `ScheduledTaskStarting`) and its closing write are the same row.
 */
class ScheduleModule extends Module
{
    public const string RUNS = 'spyglass_schedule_runs';

    public const int OUTPUT_BYTES = 10240;

    /**
     * Runs started by this process. Kept after `ScheduledTaskFinished`: for a non-zero exit code Laravel
     * fires `ScheduledTaskFailed` next, which must rewrite the same run.
     *
     * @var WeakMap<Task, array{row: array<string, mixed>, started: int|float}>
     */
    protected WeakMap $runs;

    public function __construct()
    {
        $this->runs = new WeakMap;
    }

    public function boot(): void
    {
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            $row = [
                'id' => (string) Str::ulid(),
                ...$this->describe($event->task),
                'started_at' => $this->now(),
                'status' => 'running',
                ...Spyglass::captureContext(),
            ];

            $this->runs[$event->task] = ['row' => $row, 'started' => hrtime(true)];
            $this->store($row);
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            $task = $event->task;

            // Missing on older Laravel 12 releases, where an overlap skip only leaves the exit code null.
            if (data_get($task, 'skippedBecauseOverlapping', false) || ($task->exitCode === null && ! $task->runInBackground)) {
                $this->close($task, 'skipped');
            } elseif (! $task->runInBackground) {
                $this->close($task, $task->exitCode === 0 ? 'success' : 'failed', $this->output($task));
            }
            // Background tasks are still running: `schedule:finish` reports their outcome.
        });

        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $this->close($event->task, 'failed', $this->output($event->task)));

        Event::listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $event) => Spyglass::insert(self::RUNS, [
            'id' => (string) Str::ulid(),
            ...$this->describe($event->task),
            'started_at' => $now = $this->now(),
            'finished_at' => $now,
            'duration_ms' => null,
            'status' => 'skipped',
            'exit_code' => null,
            'output' => null,
        ]));

        // Fired by `schedule:finish` in a process of its own, so the run is looked up in storage.
        Event::listen(ScheduledBackgroundTaskFinished::class, function (ScheduledBackgroundTaskFinished $event) {
            $task = $event->task;
            $run = Spyglass::ignore(function () use ($task) {
                try {
                    // Newest, not oldest: a run left open by a crash would otherwise shift every later close by one.
                    return Spyglass::connection()->table(self::RUNS)
                        ->where('task', $this->key($task))
                        ->where('host', $this->host())
                        ->where('status', 'running')
                        ->orderByDesc('started_at')
                        ->orderByDesc('id')
                        ->first();
                } catch (Throwable) {
                    return null;
                }
            });

            if ($run === null) {
                return;
            }

            $row = (array) $run;
            $this->store($row, [
                'finished_at' => $this->now(),
                'duration_ms' => max(0, (int) Date::parse($row['started_at'])->diffInMilliseconds(Date::now())),
                'status' => $task->exitCode === 0 ? 'success' : 'failed',
                'exit_code' => $task->exitCode,
                'output' => $this->output($task),
            ]);

            Spyglass::flush();
        });
    }

    public function prunable(): array
    {
        return [
            self::RUNS => ['column' => 'started_at', 'retention' => 'runs'],
        ];
    }

    /**
     * Stable task keys, aligned with `$tasks`: the command or the description, never the cron expression,
     * so the history survives a frequency change. Closures without a description use `file:line`.
     * Duplicates get a positional suffix.
     *
     * @param  array<array-key, Task>  $tasks
     * @return list<string>
     */
    public static function keys(array $tasks): array
    {
        $seen = [];

        return array_map(function (Task $task) use (&$seen) {
            $key = static::baseKey($task);
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            return $seen[$key] === 1 ? $key : "{$key} #{$seen[$key]}";
        }, array_values($tasks));
    }

    public static function timezone(Task $task): ?string
    {
        return $task->timezone instanceof DateTimeZone ? $task->timezone->getName() : $task->timezone;
    }

    protected static function baseKey(Task $task): string
    {
        $key = match (true) {
            // Normalized: the command embeds the absolute PHP binary, which differs between hosts and upgrades.
            is_string($task->command) && $task->command !== '' => Task::normalizeCommand($task->command),
            is_string($task->description) && $task->description !== '' => $task->description,
            $task instanceof CallbackEvent => static::callbackName((fn () => $this->callback)->call($task)),
            default => $task->getSummaryForDisplay(),
        };

        // Truncated before duplicates are counted, so keys sharing a long prefix still get a suffix.
        return mb_substr($key, 0, 180);
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

    protected function key(Task $task): string
    {
        $tasks = array_values(app(Schedule::class)->events());
        $index = array_search($task, $tasks, true);

        return $index === false ? static::baseKey($task) : static::keys($tasks)[$index];
    }

    /**
     * @return array<string, mixed>
     */
    protected function describe(Task $task): array
    {
        return [
            'task' => $this->key($task),
            'command' => is_string($task->command) ? Task::normalizeCommand($task->command) : null,
            'description' => is_string($task->description) ? mb_substr($task->description, 0, 255) : null,
            'expression' => $task->getExpression(),
            'timezone' => static::timezone($task),
            'host' => $this->host(),
        ];
    }

    protected function close(Task $task, string $status, ?string $output = null): void
    {
        if (! isset($this->runs[$task])) {
            return;
        }

        ['row' => $row, 'started' => $started] = $this->runs[$task];

        $this->store($row, [
            'finished_at' => $this->now(),
            'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
            'status' => $status,
            'exit_code' => $status === 'skipped' ? null : $task->exitCode,
            'output' => $output,
        ]);
    }

    /**
     * The whole row on every write: the insert half of an upsert must satisfy NOT NULL columns.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $changes
     */
    protected function store(array $row, array $changes = []): void
    {
        $values = [
            'finished_at' => null,
            'duration_ms' => null,
            'exit_code' => null,
            'output' => null,
            ...$row,
            ...$changes,
        ];
        unset($values['id']);

        Spyglass::upsert(self::RUNS, ['id' => $row['id']], $values);
    }

    /**
     * The tail of the output file, only when the task already writes one.
     */
    protected function output(Task $task): ?string
    {
        if ($task instanceof CallbackEvent || ! is_file($task->output)) {
            return null;
        }

        $handle = @fopen($task->output, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            // The tail: with `appendOutputTo` the head is older runs. Fails, staying at 0, on smaller files.
            fseek($handle, -self::OUTPUT_BYTES, SEEK_END);
            $tail = (string) stream_get_contents($handle, self::OUTPUT_BYTES);
        } finally {
            fclose($handle);
        }

        // The cut can split a character, and PostgreSQL rejects invalid UTF-8 and NUL bytes.
        return str_replace("\0", '', mb_scrub($tail, 'UTF-8'));
    }

    protected function host(): string
    {
        return mb_substr((string) gethostname(), 0, 191);
    }

    protected function now(): string
    {
        return Date::now()->format('Y-m-d H:i:s.v');
    }
}
