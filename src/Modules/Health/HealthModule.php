<?php

namespace Keepsuit\Spyglass\Modules\Health;

use Keepsuit\Spyglass\Modules\Module;

/**
 * Stores nothing: checks run when the dashboard asks for them, through `HealthQueries`.
 */
class HealthModule extends Module
{
    public static function name(): string
    {
        return 'health';
    }
}
