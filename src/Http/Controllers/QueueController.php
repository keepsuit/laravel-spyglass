<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Keepsuit\Spyglass\Dashboard\Widget;
use Keepsuit\Spyglass\Modules\Queue\Outcome;
use Keepsuit\Spyglass\Modules\Queue\QueueQueries;
use Keepsuit\Spyglass\Spyglass;

/**
 * @internal
 */
class QueueController extends Controller
{
    protected const array RANGES = ['1h' => 1, '24h' => 24, '7d' => 168];

    public const array COUNTERS = ['processed' => 'Processed', 'failed' => 'Failed', 'released' => 'Released', 'timed_out' => 'Timed out', 'queued' => 'Queued'];

    protected const string MISSING = 'Already retried or removed.';

    public function __construct(Spyglass $spyglass, protected QueueQueries $queries)
    {
        parent::__construct($spyglass);
    }

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        $filters = $this->filters($request, ['range' => 'in:'.implode(',', array_keys(self::RANGES)), 'queue' => '', 'job' => '', 'scope' => '']);
        $range = $filters['range'] ?? '24h';
        $page = $this->page($request);

        $to = now();
        $from = $this->queries->rangeStart(self::RANGES[$range]);

        // Aggregates are kept per scope only when configured: otherwise they ignore the scope filter.
        $aggregateFilters = ['range', 'queue', 'job', ...(config('spyglass.modules.queue.aggregate_by_scope') ? ['scope'] : [])];
        $aggregateBy = array_intersect_key($filters, array_flip(array_diff($aggregateFilters, ['range'])));
        $totals = $this->queries->totals($from, $to, [], $aggregateBy)->first() ?? [];

        $failedBy = array_intersect_key($filters, array_flip(['queue', 'job', 'scope']));
        $failed = $this->queries->failedJobs($failedBy, page: $page);

        $counters = array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys(self::COUNTERS), self::COUNTERS);

        return Widget::page('Queue', [
            ...array_map(fn (array $counter) => Widget::metric($counter['key'], $counter['label'], $totals[$counter['key']] ?? 0, $aggregateFilters), $counters),
            Widget::series('throughput', 'Throughput', 'bucket', $counters, $this->queries->series($from, $to, [], $aggregateBy), $aggregateFilters),
            Widget::table('sizes', 'Queues', [
                Widget::field('connection', 'Connection'),
                Widget::field('queue', 'Queue'),
                Widget::field('pending', 'Pending', 'number'),
                Widget::field('reserved', 'Running', 'number'),
                Widget::field('delayed', 'Delayed', 'number'),
            ], $this->queries->sizes(now()->subDay())->filter(fn (array $size) => ! isset($filters['queue']) || $size['queue'] === $filters['queue']), filters: ['queue']),
            Widget::table('jobs', 'Jobs', [
                Widget::field('job', 'Job'),
                Widget::field('connection', 'Connection'),
                Widget::field('queue', 'Queue'),
                ...array_map(fn (array $counter) => Widget::field($counter['key'], $counter['label'], 'number'), $counters),
                Widget::field('duration_max', 'Max duration', 'duration'),
            ], $this->queries->totals($from, $to, ['queue', 'job'], $aggregateBy), filters: $aggregateFilters),
            Widget::table('failed_jobs', 'Failed jobs', [
                Widget::field('failed_at', 'Failed at', 'datetime'),
                Widget::field('job', 'Job'),
                Widget::field('connection', 'Connection'),
                Widget::field('queue', 'Queue'),
                Widget::field('exception', 'Exception', 'link'),
                Widget::field('attempts', 'Attempts', 'number'),
                Widget::field('scope_label', 'Scope'),
            ], array_map(
                fn (array $row) => [...$row, 'exception' => $this->exceptionLink($row['exception_class'], $row['fingerprint'])],
                $this->rows($failed->items()),
            ), $failed, link: 'queue/failed-jobs/{uuid}', actions: $this->actions('{uuid}'), filters: ['queue', 'job', 'scope']),
        ], [
            Widget::filter('range', 'Range', $range, ['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days']),
            Widget::filter('queue', 'Queue', $filters['queue'] ?? null),
            Widget::filter('job', 'Job', $filters['job'] ?? null),
            Widget::filter('scope', 'Scope', $filters['scope'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $uuid): array
    {
        $job = $this->queries->failedJob($uuid);

        abort_if($job === null, 404, self::MISSING);

        $job = $this->rows([$job])[0];

        return Widget::page('Failed job', [
            Widget::details('job', 'Failed job', [
                Widget::field('uuid', 'UUID'),
                Widget::field('job', 'Job'),
                Widget::field('connection', 'Connection'),
                Widget::field('queue', 'Queue'),
                Widget::field('attempts', 'Attempts', 'number'),
                Widget::field('failed_at', 'Failed at', 'datetime'),
                Widget::field('exception_group', 'Exception group', 'link'),
                Widget::field('scope_label', 'Scope'),
                Widget::field('context', 'Context', 'json'),
                Widget::field('exception', 'Exception'),
                Widget::field('payload', 'Payload', 'json'),
            ], [...$job, 'exception_group' => $this->exceptionLink($job['exception_class'] ?? null, $job['fingerprint'] ?? null)], $this->actions($uuid)),
        ]);
    }

    public function retry(string $uuid): JsonResponse
    {
        return $this->outcome($this->queries->retry($uuid));
    }

    public function delete(string $uuid): JsonResponse
    {
        return $this->outcome($this->queries->forget($uuid));
    }

    /**
     * @return list<array{key: string, label: string, url: string}>
     */
    protected function actions(string $uuid): array
    {
        return [
            Widget::action('retry', 'Retry', "queue/failed-jobs/{$uuid}/retry"),
            Widget::action('delete', 'Delete', "queue/failed-jobs/{$uuid}/delete"),
        ];
    }

    protected function outcome(Outcome $outcome): JsonResponse
    {
        [$status, $message] = match ($outcome) {
            Outcome::Done => [200, null],
            Outcome::Missing => [404, self::MISSING],
            Outcome::Failed => [409, 'The command failed.'],
        };

        return response()->json(['outcome' => $outcome->value, 'message' => $message], $status);
    }
}
