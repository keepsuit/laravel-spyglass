<?php

namespace Keepsuit\Spyglass\Modules\Logs;

use Illuminate\Support\Facades\Log;
use Keepsuit\Spyglass\Modules\Module;
use Monolog\Logger;

class LogsModule extends Module
{
    public const string ENTRIES = 'spyglass_log_entries';

    public function boot(): void
    {
        Log::extend('spyglass', fn () => new Logger('spyglass', [
            new LogHandler(config('spyglass.modules.logs.level', 'warning')),
        ]));
    }

    public function prunable(): array
    {
        return [
            self::ENTRIES => ['column' => 'logged_at', 'retention' => 'entries'],
        ];
    }
}
