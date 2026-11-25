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
     * Tables pruned by `spyglass:prune`: rows whose datetime column is older than
     * `spyglass.modules.{module}.retention.{key}` days are deleted.
     *
     * @return array<string, array{column: string, retention: string}>
     */
    public function prunable(): array
    {
        return [];
    }
}
