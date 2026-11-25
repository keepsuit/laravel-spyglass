<?php

// Scenarios that need a PHP process of their own: the end of the process, or a second concurrent writer.
// Not through the testbench CLI, which copies files into the shared skeleton.

use Illuminate\Contracts\Console\Kernel;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\SpyglassServiceProvider;
use Keepsuit\Spyglass\Tests\Fixtures\ConcurrentException;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

$app = Application::create(options: ['extra' => ['providers' => [SpyglassServiceProvider::class]]]);

if (in_array($argv[1], ['terminating', 'fatal'], true)) {
    foreach ([__DIR__.'/../../database/migrations', __DIR__.'/../database/migrations'] as $path) {
        $app->make(Kernel::class)->call('migrate', ['--path' => $path, '--realpath' => true]);
    }
}

match ($argv[1]) {
    'terminating' => (function () use ($app) {
        $app->terminating(fn () => Spyglass::insert('spyglass_test_entries', ['name' => 'late']));
        $app->terminate();
    })(),
    'fatal' => (function () {
        ini_set('memory_limit', '64M');
        str_repeat('x', 128 * 1024 * 1024);
    })(),
    'report' => (function () {
        report(ConcurrentException::make());
        report(ConcurrentException::make());
        Spyglass::flush();
    })(),
};
