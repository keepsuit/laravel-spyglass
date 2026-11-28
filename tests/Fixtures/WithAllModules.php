<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Keepsuit\Spyglass\Modules\Schedule\ScheduleModule;

/**
 * Every module that records sources: two traits overriding the same hooks cannot be combined.
 */
trait WithAllModules
{
    use WithExceptionsAndLogsModules {
        WithExceptionsAndLogsModules::defineEnvironment as defineExceptionsAndLogsEnvironment;
    }

    protected function defineEnvironment($app): void
    {
        $this->defineExceptionsAndLogsEnvironment($app);

        $this->enableModule($app, ScheduleModule::class);
    }
}
