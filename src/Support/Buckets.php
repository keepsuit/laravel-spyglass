<?php

namespace Keepsuit\Spyglass\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Keepsuit\Spyglass\Facades\Spyglass;
use RuntimeException;

/**
 * Counts in time buckets for the charts: every bucket present, oldest first, so the dashboard can merge
 * neighbours to fit its width.
 *
 * @internal
 */
final class Buckets
{
    /**
     * The start of the `$step`-second bucket holding the moment `$hours` ago, so a range never cuts its oldest bucket.
     */
    public static function start(int $hours, int $step): CarbonInterface
    {
        $start = now()->subHours($hours)->getTimestamp();

        return now()->setTimestamp($start - $start % $step);
    }

    /**
     * `$rows` summed into `$step`-second buckets from `$from` to now, with `bucket` (UTC) and each of `$keys`,
     * 0 where nothing was recorded.
     *
     * @param  iterable<array<string, mixed>|object>  $rows  with `bucket`, a UTC datetime string, and counts by key
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    public static function fill(iterable $rows, CarbonInterface $from, int $step, array $keys): array
    {
        /** @var array<int, array<string, mixed>> $points */
        $points = [];
        $start = $from->getTimestamp() - $from->getTimestamp() % $step;

        for ($time = $start; $time <= now()->getTimestamp(); $time += $step) {
            $points[$time] = ['bucket' => gmdate('Y-m-d H:i:s', $time)] + array_fill_keys($keys, 0);
        }

        foreach ($rows as $row) {
            $row = (array) $row;
            $time = Date::parse((string) $row['bucket'], 'UTC')->getTimestamp();
            $time -= $time % $step;

            foreach (isset($points[$time]) ? $keys : [] as $key) {
                $points[$time][$key] += (int) ($row[$key] ?? 0);
            }
        }

        return array_values($points);
    }

    /**
     * Exception occurrences or log entries per hour since `$from`, for `fill()`: `bucket` and a count per value
     * of `$by`, or `count` without it. Entries are throttled, so these count recorded entries.
     *
     * @param  (Closure(Builder): mixed)|null  $where
     * @return list<array<string, mixed>>
     */
    public static function entries(string $kind, CarbonInterface $from, ?Closure $where = null, ?string $by = null): array
    {
        $db = Spyglass::connection();

        $bucket = match ($db->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d %H:00:00', recorded_at)",
            'mysql', 'mariadb' => "date_format(recorded_at, '%Y-%m-%d %H:00:00')",
            'pgsql' => "to_char(recorded_at, 'YYYY-MM-DD HH24:00:00')",
            default => throw new RuntimeException("Spyglass does not support the [{$db->getDriverName()}] database driver."),
        };

        $query = $db->table(Sources::ENTRIES)
            ->selectRaw("{$bucket} as bucket, count(*) as total")
            ->when($by, fn (Builder $query, string $by) => $query->addSelect($by)->groupBy($by))
            ->where('kind', $kind)
            ->where('recorded_at', '>=', $from->copy()->utc()->format('Y-m-d H:i:s.v'))
            ->groupByRaw($bucket);

        if ($where !== null) {
            $where($query);
        }

        return array_values($query->get()
            ->map(fn (object $row) => ['bucket' => $row->bucket, ($by === null ? 'count' : (string) $row->{$by}) => (int) $row->total])
            ->all());
    }
}
