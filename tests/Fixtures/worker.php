<?php

// A real `queue:work` process. Not through the testbench CLI: the worker kills itself on timeout,
// which would skip the CLI's cleanup of the files it copies into the skeleton.

use Illuminate\Contracts\Console\Kernel;
use Keepsuit\Spyglass\SpyglassServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/../../vendor/autoload.php';

$app = Application::create(options: ['extra' => ['providers' => [SpyglassServiceProvider::class]]]);

exit($app->make(Kernel::class)->handle(new ArgvInput, new ConsoleOutput));
