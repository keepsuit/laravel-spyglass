<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Keepsuit\Spyglass\Modules\Logs\LogsModule;
use Monolog\Handler\TestHandler;

trait WithLogsModule
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->enableModule($app, LogsModule::class);

        $app['config']->set([
            'logging.default' => 'stack',
            // A channel that accepts every level first, as `stderr` does in production.
            'logging.channels.stack' => ['driver' => 'stack', 'channels' => ['test', 'spyglass']],
            'logging.channels.test' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
