<?php

use Illuminate\Support\Facades\DB;
use Keepsuit\Spyglass\Support\ExceptionFingerprint;

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
