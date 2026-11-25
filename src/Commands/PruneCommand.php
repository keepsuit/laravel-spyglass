<?php

namespace Keepsuit\Spyglass\Commands;

use Illuminate\Console\Command;
use Keepsuit\Spyglass\Spyglass;

class PruneCommand extends Command
{
    protected $signature = 'spyglass:prune';

    protected $description = 'Delete Spyglass entries older than the configured retention';

    public function handle(Spyglass $spyglass): int
    {
        foreach ($spyglass->modules() as $name => $module) {
            foreach ($module->prunable() as $table => ['column' => $column, 'retention' => $retention]) {
                $days = config("spyglass.modules.{$name}.retention.{$retention}");

                if (! is_numeric($days)) {
                    continue;
                }

                $deleted = $spyglass->connection()->table($table)
                    ->where($column, '<', now()->subDays((int) $days))
                    ->delete();

                $this->components->twoColumnDetail($table, "{$deleted} deleted");
            }
        }

        return self::SUCCESS;
    }
}
