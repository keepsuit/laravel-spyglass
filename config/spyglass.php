<?php

use Keepsuit\Spyglass\Modules;

return [

    /*
     * Master switch. When disabled Spyglass registers no listeners, routes or schedules.
     */
    'enabled' => env('SPYGLASS_ENABLED', true),

    /*
     * Dashboard domain (null = any), URI prefix and route middleware. Access is always checked with the
     * `viewSpyglass` gate or the `Spyglass::auth()` callback. The middleware must start the session (`web`):
     * the actions check the CSRF token.
     */
    'domain' => env('SPYGLASS_DOMAIN'),

    'path' => env('SPYGLASS_PATH', 'spyglass'),

    'middleware' => ['web'],

    /*
     * Database connection for the entries (null = application default), resolved once at boot.
     */
    'storage' => [
        'connection' => env('SPYGLASS_DB_CONNECTION'),
    ],

    /*
     * Cache store (null = application default), resolved once at boot. `array` keeps it per process.
     */
    'cache' => env('SPYGLASS_CACHE_STORE'),

    /*
     * Laravel Context keys stored with every entry. Hidden context is never stored.
     * Use `Spyglass::resolveScopeUsing()` to derive a filterable scope from them.
     */
    'context' => [],

    /*
     * Register `spyglass:prune` in the application schedule (daily).
     */
    'schedule_prune' => true,

    /*
     * Modules, in dashboard order. A module left out is disabled. Retention values are in days.
     */
    'modules' => [
        Modules\Schedule\ScheduleModule::class => [
            'enabled' => env('SPYGLASS_SCHEDULE_ENABLED', true),
            'retention' => [
                'runs' => 30,
            ],
            // Runs kept per task, newest first, within the retention. Not successful: failed or interrupted.
            'keep_per_task' => [
                'successful' => 10,
                'failed' => 100,
            ],
            // Seconds after a due time before a task without a run or a skip is reported as missed.
            // Keep it above 60: skips of sub-minute tasks are written at most every 30 seconds.
            'missed_after' => 90,
            // Seconds after which a run that never reported an outcome is shown as interrupted.
            'interrupted_after' => 86400,
        ],

        Modules\Queue\QueueModule::class => [
            'enabled' => env('SPYGLASS_QUEUE_ENABLED', true),
            'retention' => [
                'aggregates' => 7,
                'failed_jobs' => 30,
            ],
            // Also keep aggregates per scope, next to the totals. Adds a row per active scope.
            'aggregate_by_scope' => false,
        ],

        Modules\Exceptions\ExceptionsModule::class => [
            'enabled' => env('SPYGLASS_EXCEPTIONS_ENABLED', true),
            'retention' => [
                'occurrences' => 30,
                'resolved_groups' => 30,
            ],
            // Query string keys stored with their value. Every other value is stored as `***`.
            'query_allowlist' => [],
        ],

        // Records what reaches the `spyglass` log channel: add it to the application's log stack.
        Modules\Logs\LogsModule::class => [
            'enabled' => env('SPYGLASS_LOGS_ENABLED', true),
            'retention' => [
                'entries' => 7,
            ],
            // Lowest level stored.
            'level' => 'warning',
        ],

        Modules\Health\HealthModule::class => [
            'enabled' => env('SPYGLASS_HEALTH_ENABLED', true),
            // Deployed version (a git tag or commit), hidden when empty. The keepsuit/laravel chart sets APP_VERSION.
            'app_version' => env('APP_VERSION'),
            // Composer packages whose installed version is shown, keyed by label.
            'packages' => [
                'Spyglass' => 'keepsuit/laravel-spyglass',
            ],
            // Database connections and cache stores checked. Null: the application default and the one Spyglass uses.
            'connections' => null,
            'cache_stores' => null,
        ],
    ],

];
