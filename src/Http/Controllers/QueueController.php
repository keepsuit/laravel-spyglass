<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Keepsuit\Spyglass\Dashboard\Widget;
use Keepsuit\Spyglass\Modules\Queue\Outcome;
use Keepsuit\Spyglass\Modules\Queue\QueueQueries;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Support\Buckets;

/**
 * @internal
 */
class QueueController extends Controller
{
    protected const array RANGES = ['1h' => 1, '24h' => 24, '7d' => 168];

    /** Chart buckets by range, in seconds: whole stored 5-minute buckets, a few hundred points at most. */
    protected const array STEPS = ['1h' => 300, '24h' => 300, '7d' => 1800];

    public const array COUNTERS = ['queued' => 'Dispatched', 'processed' => 'Processed', 'failed' => 'Failed', 'released' => 'Released', 'timed_out' => 'Timed out'];

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
        $from = Buckets::start(self::RANGES[$range], self::STEPS[$range]);

        // Aggregates are kept per scope only when configured: otherwise they ignore the scope filter.
        $aggregateFilters = ['range', 'queue', 'job', ...(config('spyglass.modules.queue.aggregate_by_scope') ? ['scope'] : [])];
        $aggregateBy = array_intersect_key($filters, array_flip(array_diff($aggregateFilters, ['range'])));
        $totals = $this->queries->totals($from, $to, [], $aggregateBy)->first() ?? [];

        $failedBy = array_intersect_key($filters, array_flip(['queue', 'job', 'scope']));
        $failed = $this->queries->failedJobs($failedBy, page: $page);

        $counters = array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys(self::COUNTERS), self::COUNTERS);
        $metrics = array_map(fn (array $counter) => Widget::metric($counter['key'], $counter['label'], $totals[$counter['key']] ?? 0, $aggregateFilters), $counters);
        $sizes = $this->queries->sizes(now()->subDay());
        $pending = $sizes->pluck('pending')->filter(fn (?int $size) => $size !== null);

        return Widget::page('Queue', [
            $metrics[0],
            // Across every queue, now: the backend reports no history.
            Widget::metric('pending', 'Pending', $pending->isEmpty() ? null : $pending->sum()),
            $metrics[1],
            $metrics[2],
            // Released and timed out attempts both run again.
            Widget::metric('retried', 'Retried', ($totals['released'] ?? 0) + ($totals['timed_out'] ?? 0), $aggregateFilters, detail: ['label' => 'Timed out', 'value' => $totals['timed_out'] ?? 0]),
            Widget::metric('duration', 'Average duration', self::averageDuration($totals), $aggregateFilters, format: 'duration', detail: ['label' => 'Max', 'value' => self::maxDuration($totals)]),
            // Dispatched last: series colors go by position and must match the overview chart.
            self::chart($this->queries->series($from, $to, [], $aggregateBy), $from, self::STEPS[$range], ['processed', 'failed', 'released', 'timed_out', 'queued'], $aggregateFilters),
            Widget::table('sizes', 'Queues', [
                Widget::field('connection', 'Connection'),
                Widget::field('queue', 'Queue'),
                Widget::field('pending', 'Pending', 'number', sortable: true),
                Widget::field('reserved', 'Running', 'number', sortable: true),
                Widget::field('delayed', 'Delayed', 'number', sortable: true),
            ], $sizes->filter(fn (array $size) => ! isset($filters['queue']) || $size['queue'] === $filters['queue']), filters: ['queue']),
            Widget::table('jobs', 'Jobs', [
                Widget::field('job', 'Job', 'link', sortable: true),
                Widget::field('connection', 'Connection'),
                Widget::field('queue', 'Queue'),
                ...array_map(fn (array $counter) => Widget::field($counter['key'], $counter['label'], 'number', sortable: true), $counters),
                Widget::field('duration_max', 'Max duration', 'duration', sortable: true),
            ], $this->queries->totals($from, $to, ['queue', 'job'], $aggregateBy)->map(
                fn (array $row) => [...$row, 'job' => $this->jobLink($row['job'], 'queue?'.http_build_query([...$filters, 'job' => $row['job']]))],
            ), filters: $aggregateFilters),
            Widget::table('failed_jobs', 'Failed jobs', [
                Widget::field('failed_at', 'Failed at', 'datetime'),
                Widget::field('job', 'Job', 'link'),
                Widget::field('connection', 'Connection'),
                Widget::field('queue', 'Queue'),
                Widget::field('exception', 'Exception', 'link'),
                Widget::field('attempts', 'Attempts', 'number'),
                Widget::field('scope_label', 'Scope'),
            ], array_map(
                fn (array $row) => [...$row, 'job' => $this->jobLink($row['job']), 'exception' => $this->exceptionLink($row['exception_class'], $row['fingerprint'])],
                $this->rows($failed->items()),
            ), $failed, link: 'queue/failed-jobs/{uuid}', actions: $this->actions('{uuid}'), filters: ['queue', 'job', 'scope']),
        ], [
            Widget::filter('range', 'Range', $range, ['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days']),
            Widget::filter('queue', 'Queue', $filters['queue'] ?? null, suggestions: $this->suggestions($this->queries->queues($from)->pluck('queue'))),
            Widget::filter('job', 'Job', $filters['job'] ?? null, suggestions: array_map(fn (string $job) => $this->jobLink($job)['label'] ?? $job, $this->suggestions($this->queries->totals($from, $to, ['job'])->pluck('job')))),
            Widget::filter('scope', 'Scope', $filters['scope'] ?? null, suggestions: $this->suggestions($this->queries->scopes(), scopes: true)),
        ]);
    }

    /**
     * Counters over time, in buckets of `$step` seconds.
     *
     * @param  iterable<array<string, mixed>>  $rows  as `QueueQueries::series()`
     * @param  list<string>  $keys
     * @param  list<string>  $filters
     * @return array<string, mixed>
     */
    public static function chart(iterable $rows, CarbonInterface $from, int $step, array $keys, array $filters = [], string $label = 'Throughput'): array
    {
        $lines = array_map(fn (string $key) => ['key' => $key, 'label' => self::COUNTERS[$key]], $keys);

        return Widget::series('throughput', $label, 'bucket', $lines, Buckets::fill($rows, $from, $step, $keys), $filters, step: $step);
    }

    /**
     * Milliseconds per attempt: queued jobs have no duration.
     *
     * @param  array<string, mixed>  $totals
     */
    protected static function averageDuration(array $totals): ?int
    {
        $attempts = array_sum(array_intersect_key($totals, array_flip(['processed', 'failed', 'released', 'timed_out'])));

        return $attempts > 0 ? (int) round(($totals['duration_sum'] ?? 0) / $attempts) : null;
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    protected static function maxDuration(array $totals): ?int
    {
        return self::averageDuration($totals) === null ? null : (int) ($totals['duration_max'] ?? 0);
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
                Widget::field('uuid', 'UUID', 'code'),
                Widget::field('job', 'Job', 'code'),
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
