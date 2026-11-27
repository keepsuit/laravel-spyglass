<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Keepsuit\Spyglass\Http\Descriptor as D;
use Keepsuit\Spyglass\Modules\Queue\Outcome;
use Keepsuit\Spyglass\Modules\Queue\QueueQueries;
use Keepsuit\Spyglass\Spyglass;

/**
 * @internal
 */
class QueueController extends Controller
{
    protected const array RANGES = ['1h' => 1, '24h' => 24, '7d' => 168];

    protected const array COUNTERS = ['processed' => 'Processed', 'failed' => 'Failed', 'released' => 'Released', 'timed_out' => 'Timed out', 'queued' => 'Queued'];

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
        // From the start of the bucket holding the range start, so the oldest bucket is not left out.
        $start = now()->subHours(self::RANGES[$range])->getTimestamp();
        $from = now()->setTimestamp($start - $start % 300);

        // Aggregates are kept per scope only when configured: otherwise they ignore the scope filter.
        $aggregateFilters = ['range', 'queue', 'job', ...(config('spyglass.modules.queue.aggregate_by_scope') ? ['scope'] : [])];
        $aggregateBy = array_intersect_key($filters, array_flip(array_diff($aggregateFilters, ['range'])));
        $totals = $this->queries->totals($from, $to, [], $aggregateBy)->first() ?? [];

        $failedBy = array_intersect_key($filters, array_flip(['queue', 'job', 'scope']));
        $failed = $this->queries->failedJobs($failedBy, page: $page);

        $counters = array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys(self::COUNTERS), self::COUNTERS);

        return D::page('Queue', [
            ...array_map(fn (array $counter) => D::metric($counter['key'], $counter['label'], $totals[$counter['key']] ?? 0, $aggregateFilters), $counters),
            D::series('throughput', 'Throughput', 'bucket', $counters, $this->queries->series($from, $to, [], $aggregateBy), $aggregateFilters),
            D::table('sizes', 'Queues', [
                D::field('connection', 'Connection'),
                D::field('queue', 'Queue'),
                D::field('pending', 'Pending', 'number'),
                D::field('reserved', 'Running', 'number'),
                D::field('delayed', 'Delayed', 'number'),
            ], $this->queries->sizes(now()->subDay())->filter(fn (array $size) => ! isset($filters['queue']) || $size['queue'] === $filters['queue']), filters: ['queue']),
            D::table('jobs', 'Jobs', [
                D::field('job', 'Job'),
                D::field('connection', 'Connection'),
                D::field('queue', 'Queue'),
                ...array_map(fn (array $counter) => D::field($counter['key'], $counter['label'], 'number'), $counters),
                D::field('duration_max', 'Max duration', 'duration'),
            ], $this->queries->totals($from, $to, ['queue', 'job'], $aggregateBy), filters: $aggregateFilters),
            D::table('failed_jobs', 'Failed jobs', [
                D::field('failed_at', 'Failed at', 'datetime'),
                D::field('job', 'Job'),
                D::field('connection', 'Connection'),
                D::field('queue', 'Queue'),
                D::field('exception', 'Exception', 'link'),
                D::field('attempts', 'Attempts', 'number'),
                D::field('scope_label', 'Scope'),
            ], array_map(
                fn (array $row) => [...$row, 'exception' => $this->exceptionLink($row['exception_class'], $row['fingerprint'])],
                $this->rows($failed->items()),
            ), $failed, link: 'queue/failed-jobs/{uuid}', actions: $this->actions('{uuid}'), filters: ['queue', 'job', 'scope']),
        ], [
            D::filter('range', 'Range', $range, ['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days']),
            D::filter('queue', 'Queue', $filters['queue'] ?? null),
            D::filter('job', 'Job', $filters['job'] ?? null),
            D::filter('scope', 'Scope', $filters['scope'] ?? null),
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

        return D::page('Failed job', [
            D::details('job', 'Failed job', [
                D::field('uuid', 'UUID'),
                D::field('job', 'Job'),
                D::field('connection', 'Connection'),
                D::field('queue', 'Queue'),
                D::field('attempts', 'Attempts', 'number'),
                D::field('failed_at', 'Failed at', 'datetime'),
                D::field('exception_group', 'Exception group', 'link'),
                D::field('scope_label', 'Scope'),
                D::field('context', 'Context', 'json'),
                D::field('exception', 'Exception'),
                D::field('payload', 'Payload', 'json'),
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
            D::action('retry', 'Retry', "queue/failed-jobs/{$uuid}/retry"),
            D::action('delete', 'Delete', "queue/failed-jobs/{$uuid}/delete"),
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
