<?php

namespace Keepsuit\Spyglass\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Query\Builder;
use Keepsuit\Spyglass\Facades\Spyglass;
use RuntimeException;

/**
 * Exception occurrences and log entries per hour, for the charts. Entries are throttled, so these count
 * recorded entries, not everything that happened.
 *
 * @internal
 */
final class Hourly
{
    /**
     * The start of the oldest of `$hours` hourly buckets ending with the current one.
     */
    public static function start(int $hours): CarbonInterface
    {
        return now()->utc()->startOfHour()->subHours(max(1, $hours) - 1);
    }

    /**
     * Every hour from `$from` to now, oldest first, with `bucket` (UTC) and a count per value of `$by`,
     * or `count` without it. Values without entries in an hour are left out of it.
     *
     * @param  (Closure(Builder): mixed)|null  $where
     * @return list<array<string, mixed>>
     */
    public static function counts(string $kind, CarbonInterface $from, ?Closure $where = null, ?string $by = null): array
    {
        $db = Spyglass::connection();
        $from = $from->copy()->utc()->startOfHour();

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
            ->where('recorded_at', '>=', $from->format('Y-m-d H:i:s.v'))
            ->groupByRaw($bucket);

        if ($where !== null) {
            $where($query);
        }

        $rows = $query->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->bucket][$by === null ? 'count' : (string) $row->{$by}] = (int) $row->total;
        }

        $points = [];

        // Reassigned: the application may use immutable dates.
        for ($hour = $from; $hour->lte(now()); $hour = $hour->addHour()) {
            $key = $hour->format('Y-m-d H:00:00');
            $points[] = ['bucket' => $key, ...($counts[$key] ?? [])];
        }

        return $points;
    }
}
