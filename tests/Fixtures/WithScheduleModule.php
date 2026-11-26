<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Keepsuit\Spyglass\Modules\Schedule\ScheduleModule;
use Keepsuit\Spyglass\Spyglass;

trait WithScheduleModule
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        Spyglass::$modules['schedule'] = ScheduleModule::class;
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
