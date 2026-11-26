<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Keepsuit\Spyglass\Facades\Spyglass as SpyglassFacade;
use Keepsuit\Spyglass\Modules\Exceptions\Throttle;
use Keepsuit\Spyglass\Spyglass;

function useSpyglassCache(string $store): void
{
    app()->instance(Spyglass::class, new Spyglass(SpyglassFacade::connection()->getName(), $store));
    SpyglassFacade::clearResolvedInstance(Spyglass::class);
}

it('takes the slot of a key that expires between the add and the check', function () {
    Cache::extend('expiring', fn () => Cache::repository(new class extends ArrayStore
    {
        public bool $expireOnce = true;

        public function add($key, $value, $seconds)
        {
            // Another process holds the key, which expires right after this add.
            if ($this->expireOnce) {
                $this->expireOnce = false;

                return false;
            }

            if ($this->get($key) !== null) {
                return false;
            }

            return $this->put($key, $value, $seconds);
        }
    }));
    config()->set('cache.stores.expiring', ['driver' => 'expiring']);
    useSpyglassCache('expiring');

    expect((new Throttle)->acquire('key'))->toBeTrue()
        // Another process.
        ->and((new Throttle)->acquire('key'))->toBeFalse();
});

it('never queries a database cache store, which could abort an open transaction', function () {
    config()->set('cache.stores.database', ['driver' => 'database', 'connection' => DB::getDefaultConnection(), 'table' => 'spyglass_missing_cache']);
    useSpyglassCache('database');

    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        $queries++;
    });

    expect((new Throttle)->acquire('key'))->toBeTrue()
        ->and((new Throttle)->acquire('key'))->toBeTrue()
        ->and($queries)->toBe(0)
        ->and($this->spyglassErrors)->toBe([]);
});
