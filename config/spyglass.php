<?php

return [

    /*
     * Master switch. When disabled Spyglass registers no listeners, routes or schedules.
     */
    'enabled' => env('SPYGLASS_ENABLED', true),

    /*
     * Dashboard URI prefix and route middleware. Access is always checked
     * with the `viewSpyglass` gate or the `Spyglass::auth()` callback.
     */
    'path' => env('SPYGLASS_PATH', 'spyglass'),

    'middleware' => ['web'],

    /*
     * Database connection used to store entries (null = application default).
     * The name is resolved once when the application boots, so runtime switches
     * of the default connection (e.g. per-tenant databases) do not affect it.
     * Entries are written through a copy of it registered as `spyglass`, outside
     * the application's transactions (SQLite: through the connection itself).
     */
    'storage' => [
        'connection' => env('SPYGLASS_DB_CONNECTION'),
    ],

    /*
     * Cache store used by Spyglass (null = application default), resolved once at boot.
     */
    'cache' => [
        'store' => env('SPYGLASS_CACHE_STORE'),
    ],

    /*
     * Laravel Context keys stored with every entry. Hidden context is never stored.
     * Use `Spyglass::resolveScopeUsing()` to derive a filterable scope from them.
     */
    'context' => [
        'keys' => [],
    ],

    /*
     * Modules and their data retention in days.
     */
    'modules' => [
        'schedule' => [
            'enabled' => true,
            'retention' => [
                'runs' => 30,
            ],
        ],

        'queue' => [
            'enabled' => true,
            'retention' => [
                'aggregates' => 7,
                'failed_jobs' => 30,
            ],
            // Split aggregates by scope. Multiplies their rows by the number of scopes.
            'aggregate_by_scope' => false,
        ],

        'exceptions' => [
            'enabled' => true,
            'retention' => [
                'occurrences' => 30,
                'resolved_groups' => 30,
            ],
        ],

        'logs' => [
            'enabled' => true,
            'retention' => [
                'entries' => 7,
            ],
        ],

        'health' => [
            'enabled' => true,
        ],
    ],

    /*
     * Register `spyglass:prune` in the application schedule (daily).
     */
    'prune' => [
        'schedule' => true,
    ],

];
