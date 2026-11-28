<?php

namespace Keepsuit\Spyglass\Modules;

/**
 * @internal
 */
abstract class Module
{
    /**
     * Key of the module in URLs and in the API: renaming the class must not change it.
     */
    abstract public static function name(): string;

    /**
     * An option from this module's entry in `spyglass.modules`.
     */
    public static function config(string $key, mixed $default = null): mixed
    {
        return config('spyglass.modules.'.static::class.'.'.$key, $default);
    }

    /**
     * Register event listeners. Called at boot, only when the module is enabled.
     */
    public function boot(): void {}

    /**
     * Tables pruned by `spyglass:prune`: rows whose datetime column (stored in UTC) is older than
     * `retention.{key}` days of the module config are deleted, only those of `kind` in a shared table.
     *
     * @return array<string, array{column: string, retention: string, kind?: string}>
     */
    public function prunable(): array
    {
        return [];
    }

    /**
     * Cleanup that retention cannot express, run by `spyglass:prune`.
     *
     * @return array<string, int> deleted rows by table
     */
    public function prune(): array
    {
        return [];
    }
}
