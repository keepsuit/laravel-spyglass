<?php

namespace Keepsuit\Spyglass\Modules\Exceptions;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Date;
use Keepsuit\Spyglass\Facades\Spyglass;
use Throwable;

/**
 * At most one slot per key and minute, across processes through the cache and within the process through memory.
 *
 * @internal
 */
final class Throttle
{
    private const int SECONDS = 60;

    private const int CAPACITY = 1000;

    /** @var array<string, int> key => expiry timestamp */
    private array $taken = [];

    private int $cacheDownUntil = 0;

    public function acquire(string $key): bool
    {
        $now = Date::now()->getTimestamp();

        if (($this->taken[$key] ?? 0) > $now) {
            return false;
        }

        unset($this->taken[$key]);

        if (count($this->taken) >= self::CAPACITY) {
            $this->taken = array_filter($this->taken, fn (int $expiry) => $expiry > $now);
        }

        if (count($this->taken) >= self::CAPACITY) {
            unset($this->taken[array_key_first($this->taken)]);
        }

        $this->taken[$key] = $now + self::SECONDS;

        // After a failure the process limit alone applies for a while, so an outage costs one timeout a minute.
        if ($this->cacheDownUntil > $now) {
            return true;
        }

        try {
            $cache = Spyglass::cache();

            // A failing query on the application's connection would abort its open transaction on PostgreSQL.
            if ($cache->getStore() instanceof DatabaseStore) {
                return true;
            }

            if ($cache->add($key, true, self::SECONDS)) {
                return true;
            }

            if ($cache->has($key)) {
                return false;
            }

            // Expired since the add: take it now, so other processes wait. A store that keeps nothing, such as
            // `null`, can never add: the process limit alone applies.
            $cache->add($key, true, self::SECONDS);

            return true;
        } catch (Throwable $e) {
            $this->cacheDownUntil = $now + self::SECONDS;
            Spyglass::report('Spyglass failed to throttle through the cache', $e);

            return true;
        }
    }
}
