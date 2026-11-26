<?php

namespace Keepsuit\Spyglass\Modules\Logs;

use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Keepsuit\Spyglass\Modules\Module;
use Keepsuit\Spyglass\Support\Sources;
use Monolog\Logger;

class LogsModule extends Module
{
    public const string ENTRIES = 'spyglass_log_entries';

    public function boot(): void
    {
        $handler = null;

        Log::extend('spyglass', function () use (&$handler) {
            return new Logger('spyglass', [$handler = new LogHandler(config('spyglass.modules.logs.level', 'warning'))]);
        });

        // A worker or an Octane server runs many lifecycles in one process. Sync jobs belong to the dispatching one.
        Event::listen([JobProcessing::class, ScheduledTaskStarting::class, 'Laravel\Octane\Events\RequestReceived'], function (mixed $event = null) use (&$handler) {
            if (! ($event instanceof JobProcessing && $event->job instanceof SyncJob)) {
                $handler?->startLifecycle();
            }
        });
    }

    public function prunable(): array
    {
        return [
            self::ENTRIES => ['column' => 'logged_at', 'retention' => 'entries'],
        ];
    }

    /**
     * Sources of log messages last seen before the `entries` retention.
     */
    public function prune(): array
    {
        return [Sources::TABLE.' (logs)' => app(Sources::class)->prune('log', config('spyglass.modules.logs.retention.entries'))];
    }

    /**
     * The key of a log message among the sources: its level and its stored message.
     */
    public static function signature(string $level, string $message): string
    {
        return sha1($level.'|'.$message);
    }
}
