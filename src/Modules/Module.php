<?php

namespace Keepsuit\Spyglass\Modules;

/**
 * @internal
 */
abstract class Module
{
    /**
     * Register event listeners. Called at boot, only when the module is enabled.
     */
    public function boot(): void {}

    /**
     * Tables pruned by `spyglass:prune`: rows whose datetime column (stored in UTC) is older than
     * `spyglass.modules.{module}.retention.{key}` days are deleted, only those of `kind` in a shared table.
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
