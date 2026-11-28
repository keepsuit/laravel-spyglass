<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Keepsuit\Spyglass\Modules\Queue\QueueModule;

trait WithQueueModule
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->enableModule($app, QueueModule::class);

        $app['config']->set([
            'queue.failed.driver' => 'database-uuids',
            'queue.failed.database' => $app['config']->get('database.default'),
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
