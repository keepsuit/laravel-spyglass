<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;

trait WithExceptionsModule
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->enableModule($app, ExceptionsModule::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
