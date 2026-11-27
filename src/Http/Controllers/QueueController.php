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
    /** Hours shown by each range. */
    protected const array RANGES = ['1h' => 1, '24h' => 24, '7d' => 168];

    protected const array COUNTERS = ['processed' => 'Processed', 'failed' => 'Failed', 'released' => 'Released', 'timed_out' => 'Timed out', 'queued' => 'Queued'];

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
        unset($filters['range']);

        $to = now();
        $from = now()->subHours(self::RANGES[$range]);
        $totals = $this->queries->totals($from, $to, [], $filters)->first() ?? [];
        $lines = array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys(self::COUNTERS), self::COUNTERS);
        $failed = $this->queries->failedJobs($filters, page: $this->page($request));

        $filterFields = [
            D::filter('range', 'Range', $range, ['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days']),
            D::filter('queue', 'Queue', $filters['queue'] ?? null),
            D::filter('job', 'Job', $filters['job'] ?? null),
            D::filter('scope', 'Scope', $filters['scope'] ?? null),
        ];

        return D::page('Queue', [
            ...array_map(fn ($key, $label) => D::metric($key, $label, $totals[$key] ?? 0), array_keys(self::COUNTERS), self::COUNTERS),
            D::series('throughput', 'Throughput', 'bucket', $lines, $this->queries->series($from, $to, [], $filters)),
            D::table('sizes', 'Queues', [
                D::field('connection', 'Connection'),
                D::field('queue', 'Queue'),
                D::field('pending', 'Pending', 'number'),
                D::field('reserved', 'Running', 'number'),
                D::field('delayed', 'Delayed', 'number'),
            ], $this->queries->sizes(now()->subDay())),
            D::table('jobs', 'Jobs', [
                D::field('job', 'Job'),
                D::field('queue', 'Queue'),
                ...array_map(fn ($key, $label) => D::field($key, $label, 'number'), array_keys(self::COUNTERS), self::COUNTERS),
                D::field('duration_max', 'Max duration', 'duration'),
            ], $this->queries->totals($from, $to, ['queue', 'job'], $filters)),
            D::table('failed_jobs', 'Failed jobs', [
                D::field('failed_at', 'Failed at', 'datetime'),
                D::field('job', 'Job'),
                D::field('queue', 'Queue'),
                D::field('exception_class', 'Exception'),
                D::field('attempts', 'Attempts', 'number'),
                D::field('scope', 'Scope'),
            ], $this->rows($failed->items()), $failed, link: 'queue/failed-jobs/{uuid}', actions: [
                D::action('retry', 'Retry', 'queue/failed-jobs/{uuid}/retry'),
                D::action('delete', 'Delete', 'queue/failed-jobs/{uuid}/delete'),
            ]),
        ], $filterFields);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $uuid): array
    {
        $job = $this->queries->failedJob($uuid);

        abort_if($job === null, 404);

        return D::page('Failed job', [
            D::details('job', 'Failed job', [
                D::field('uuid', 'UUID'),
                D::field('connection', 'Connection'),
                D::field('queue', 'Queue'),
                D::field('failed_at', 'Failed at', 'datetime'),
                D::field('exception', 'Exception'),
                D::field('payload', 'Payload', 'json'),
            ], (array) $job, [
                D::action('retry', 'Retry', "queue/failed-jobs/{$uuid}/retry"),
                D::action('delete', 'Delete', "queue/failed-jobs/{$uuid}/delete"),
            ]),
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

    protected function outcome(Outcome $outcome): JsonResponse
    {
        return response()->json(['outcome' => $outcome->value], match ($outcome) {
            Outcome::Done => 200,
            Outcome::Missing => 404,
            Outcome::Failed => 500,
        });
    }
}
