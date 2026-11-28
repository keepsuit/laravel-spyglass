<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Module;

class TestModule extends Module
{
    public static bool $booted = false;

    public static function name(): string
    {
        return 'test';
    }

    public function boot(): void
    {
        static::$booted = true;

        Event::listen(JobFailed::class, fn () => Spyglass::insert('spyglass_test_entries', ['name' => 'job-failed']));
        Event::listen(ScheduledTaskFinished::class, fn () => Spyglass::insert('spyglass_test_entries', ['name' => 'task-finished']));
    }

    public function prunable(): array
    {
        return [
            'spyglass_test_entries' => ['column' => 'created_at', 'retention' => 'entries'],
        ];
    }
}
