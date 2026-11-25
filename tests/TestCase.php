<?php

namespace Keepsuit\Spyglass\Tests;

use Keepsuit\Spyglass\SpyglassServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SpyglassServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
    }
}
