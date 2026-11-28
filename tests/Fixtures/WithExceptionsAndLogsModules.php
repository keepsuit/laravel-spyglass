<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;

/**
 * Both modules in one trait: two traits overriding the same hooks cannot be combined.
 */
trait WithExceptionsAndLogsModules
{
    use WithLogsModule {
        WithLogsModule::defineEnvironment as defineLogsEnvironment;
    }

    protected function defineEnvironment($app): void
    {
        $this->defineLogsEnvironment($app);

        $this->enableModule($app, ExceptionsModule::class);
    }
}
