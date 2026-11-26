<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Keepsuit\Spyglass\Modules\Schedule\ScheduleModule;
use Keepsuit\Spyglass\Spyglass;

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

        Spyglass::$modules['schedule'] = ScheduleModule::class;
    }
}
