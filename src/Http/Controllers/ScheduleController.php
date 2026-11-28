<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Illuminate\Http\Request;
use Keepsuit\Spyglass\Dashboard\Widget;
use Keepsuit\Spyglass\Modules\Schedule\ScheduleQueries;
use Keepsuit\Spyglass\Spyglass;

/**
 * @internal
 */
class ScheduleController extends Controller
{
    public const array TONES = ['success' => 'ok', 'running' => 'info', 'interrupted' => 'warning', 'failed' => 'error'];

    public function __construct(Spyglass $spyglass, protected ScheduleQueries $queries)
    {
        parent::__construct($spyglass);
    }

    /**
     * @return array<string, mixed>
     */
    public function index(): array
    {
        return Widget::page('Schedule', [
            Widget::table('tasks', 'Tasks', [
                Widget::field('name', 'Task', 'label', sortable: true),
                Widget::field('type', 'Type', 'status'),
                Widget::field('expressions', 'Expression', 'lines'),
                Widget::field('last_status', 'Last run', 'status', self::TONES),
                Widget::field('recent', 'Recent runs', 'runs', self::TONES),
                Widget::field('last_started_at', 'Last started at', 'datetime', sortable: true),
                Widget::field('last_duration_ms', 'Duration', 'duration', sortable: true),
                Widget::field('next_run', 'Next run', 'datetime', sortable: true),
                Widget::field('missed', 'Missed', 'boolean'),
                Widget::field('stale', 'Removed', 'boolean'),
            ], $this->queries->tasks()->map(self::flatten(...)), link: 'schedule/{key}'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Request $request, string $task): array
    {
        $page = $this->page($request);
        $details = $this->queries->tasks()->firstWhere('key', $task);

        abort_if($details === null, 404);

        $runs = $this->queries->runs($task, page: $page);

        return Widget::page($details['label'].($details['detail'] === null ? '' : " ({$details['detail']})"), [
            Widget::details('task', 'Task', [
                Widget::field('label', 'Task'),
                Widget::field('type', 'Type', 'status'),
                Widget::field('detail', 'Arguments'),
                Widget::field('command', 'Command', 'code'),
                Widget::field('description', 'Description'),
                Widget::field('schedule_lines', 'Schedule', 'lines'),
                Widget::field('environment_list', 'Environments'),
                Widget::field('without_overlapping', 'Without overlapping', 'boolean'),
                Widget::field('next_run', 'Next run', 'datetime'),
                Widget::field('missed', 'Missed', 'boolean'),
                Widget::field('overlapping', 'Overlapping', 'boolean'),
                Widget::field('stale', 'Removed', 'boolean'),
                Widget::field('last_skipped_at', 'Last skipped at', 'datetime'),
                Widget::field('last_skip_reason', 'Last skip reason'),
                Widget::field('first_seen_at', 'First seen at', 'datetime'),
                Widget::field('last_seen_at', 'Last seen at', 'datetime'),
            ], self::flatten($details)),
            Widget::table('runs', 'Runs', [
                Widget::field('started_at', 'Started at', 'datetime'),
                Widget::field('status', 'Status', 'status', self::TONES),
                Widget::field('duration_ms', 'Duration', 'duration'),
                Widget::field('exit_code', 'Exit code', 'number'),
                Widget::field('overlapped', 'Overlapped', 'boolean'),
                Widget::field('exception', 'Exception', 'link'),
                Widget::field('host', 'Host'),
                Widget::field('scope_label', 'Scope'),
                Widget::field('output', 'Output'),
            ], array_map(
                fn (array $run) => [...$run, 'exception' => $this->exceptionLink($run['exception_class'], $run['fingerprint'])],
                $this->rows($runs->items()),
            ), $runs),
        ]);
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    public static function flatten(array $task): array
    {
        $run = $task['last_run'] ?? [];
        unset($task['last_run']);

        /** @var list<array{expression: string, timezone: string, repeat_seconds: ?int}> $schedules as `ScheduleQueries::tasks()` */
        $schedules = $task['schedules'] ?? [];

        return [
            ...$task,
            'name' => ['text' => $task['label'], 'note' => $task['detail'] ?? null, 'title' => $task['description'] ?? $task['command'] ?? null],
            'expressions' => array_map(self::expression(...), $schedules),
            'environment_list' => ($task['environments'] ?? []) === [] ? 'All' : implode(', ', $task['environments']),
            'schedule_lines' => array_map(fn (array $schedule) => self::expression($schedule)." ({$schedule['timezone']})", $schedules),
            'last_status' => $run['status'] ?? null,
            'last_started_at' => $run['started_at'] ?? null,
            'last_duration_ms' => $run['duration_ms'] ?? null,
        ];
    }

    /**
     * @param  array{expression: string, repeat_seconds: ?int}  $schedule
     */
    protected static function expression(array $schedule): string
    {
        return $schedule['expression'].($schedule['repeat_seconds'] ? " every {$schedule['repeat_seconds']}s" : '');
    }
}
