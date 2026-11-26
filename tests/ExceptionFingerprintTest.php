<?php

use Illuminate\Support\Facades\DB;
use Keepsuit\Spyglass\Support\ExceptionFingerprint;

class FingerprintTarget
{
    public function sync(bool $early): never
    {
        if ($early) {
            throw new RuntimeException('early');
        }

        throw new RuntimeException('late');
    }

    public function import(): never
    {
        throw new RuntimeException('import');
    }
}

it('groups by the function that threw, so moving the throw site keeps the group', function () {
    $sync = fn (bool $early) => thrown(fn () => (new FingerprintTarget)->sync($early));

    expect(ExceptionFingerprint::for($sync(true)))->toBe(ExceptionFingerprint::for($sync(false)))
        ->and(ExceptionFingerprint::for($sync(true)))->not->toBe(ExceptionFingerprint::for(thrown(fn () => (new FingerprintTarget)->import())));
});

it('groups by class and throw site, ignoring the message', function () {
    $make = fn (string $message) => new RuntimeException($message);

    expect(ExceptionFingerprint::for($make('order 1')))->toBe(ExceptionFingerprint::for($make('order 2')))
        ->and(ExceptionFingerprint::for(new RuntimeException('x')))->not->toBe(ExceptionFingerprint::for(new LogicException('x')));
});

it('does not depend on the deploy path', function () {
    $thrownAt = function (string $file): RuntimeException {
        $exception = new RuntimeException('x');
        (new ReflectionProperty(Exception::class, 'file'))->setValue($exception, $file);

        return $exception;
    };

    $release = $thrownAt(base_path('app/Jobs/SyncOrders.php'));
    $otherRelease = $thrownAt('/srv/releases/42/app/Jobs/SyncOrders.php');

    $fingerprint = ExceptionFingerprint::for($release);
    expect($fingerprint)->toBe(sha1(RuntimeException::class.'|app/Jobs/SyncOrders.php|'.$release->getLine()));

    app()->setBasePath('/srv/releases/42');

    expect(ExceptionFingerprint::for($otherRelease))->toBe($fingerprint);
});

function thrown(Closure $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $e) {
        return $e;
    }

    throw new LogicException('Nothing was thrown.');
}

it('locates exceptions thrown by vendor code at the first application frame', function () {
    app()->setBasePath(dirname(__DIR__));

    $line = __LINE__ + 1;
    $first = thrown(fn () => throw_if(true, RuntimeException::class));
    $second = thrown(fn () => throw_if(true, RuntimeException::class));
    $query = thrown(fn () => DB::select('select * from spyglass_missing'));
    $otherQuery = thrown(fn () => DB::select('select * from spyglass_missing'));

    expect(ExceptionFingerprint::location($first))->toBe(['tests/ExceptionFingerprintTest.php', $line])
        ->and(ExceptionFingerprint::for($first))->not->toBe(ExceptionFingerprint::for($second))
        ->and(ExceptionFingerprint::for($query))->not->toBe(ExceptionFingerprint::for($otherQuery));
});
