<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;
use Keepsuit\Spyglass\Spyglass;

trait WithExceptionsModule
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        Spyglass::$modules['exceptions'] = ExceptionsModule::class;
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
