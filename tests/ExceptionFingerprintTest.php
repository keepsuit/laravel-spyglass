<?php

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
