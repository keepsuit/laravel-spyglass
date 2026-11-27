<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Illuminate\Http\Request;
use Keepsuit\Spyglass\Http\Descriptor as D;
use Keepsuit\Spyglass\Modules\Schedule\ScheduleQueries;
use Keepsuit\Spyglass\Spyglass;

/**
 * @internal
 */
class ScheduleController extends Controller
{
    protected const array TONES = ['success' => 'ok', 'running' => 'info', 'interrupted' => 'warning', 'failed' => 'error'];

    public function __construct(Spyglass $spyglass, protected ScheduleQueries $queries)
    {
        parent::__construct($spyglass);
    }

    /**
     * @return array<string, mixed>
     */
    public function index(): array
    {
        return D::page('Schedule', [
            D::table('tasks', 'Tasks', [
                D::field('label', 'Task'),
                D::field('expression', 'Expression'),
                D::field('last_status', 'Last run', 'status', self::TONES),
                D::field('last_started_at', 'Last started at', 'datetime'),
                D::field('last_duration_ms', 'Duration', 'duration'),
                D::field('next_run', 'Next run', 'datetime'),
                D::field('missed', 'Missed', 'boolean'),
                D::field('stale', 'Removed', 'boolean'),
            ], $this->queries->tasks()->map($this->flatten(...)), link: 'schedule/{key}'),
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

        return D::page($details['label'], [
            D::details('task', 'Task', [
                D::field('label', 'Task'),
                D::field('command', 'Command'),
                D::field('description', 'Description'),
                D::field('expression', 'Expression'),
                D::field('repeat_seconds', 'Repeats every (s)', 'number'),
                D::field('timezone', 'Timezone'),
                D::field('environments', 'Environments', 'json'),
                D::field('without_overlapping', 'Without overlapping', 'boolean'),
                D::field('next_run', 'Next run', 'datetime'),
                D::field('missed', 'Missed', 'boolean'),
                D::field('overlapping', 'Overlapping', 'boolean'),
                D::field('stale', 'Removed', 'boolean'),
                D::field('last_skipped_at', 'Last skipped at', 'datetime'),
                D::field('last_skip_reason', 'Last skip reason'),
                D::field('first_seen_at', 'First seen at', 'datetime'),
                D::field('last_seen_at', 'Last seen at', 'datetime'),
            ], $this->flatten($details)),
            D::table('runs', 'Runs', [
                D::field('started_at', 'Started at', 'datetime'),
                D::field('status', 'Status', 'status', self::TONES),
                D::field('duration_ms', 'Duration', 'duration'),
                D::field('exit_code', 'Exit code', 'number'),
                D::field('overlapped', 'Overlapped', 'boolean'),
                D::field('exception', 'Exception', 'link'),
                D::field('host', 'Host'),
                D::field('scope_label', 'Scope'),
                D::field('output', 'Output'),
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
    protected function flatten(array $task): array
    {
        $run = $task['last_run'] ?? [];
        unset($task['last_run']);

        return [
            ...$task,
            'last_status' => $run['status'] ?? null,
            'last_started_at' => $run['started_at'] ?? null,
            'last_duration_ms' => $run['duration_ms'] ?? null,
        ];
    }
}
