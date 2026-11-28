<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Keepsuit\Spyglass\Modules\Schedule\ScheduleModule;

trait WithScheduleModule
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->enableModule($app, ScheduleModule::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
