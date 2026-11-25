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

                $query = $spyglass->connection()->table($table)
                    ->where($column, '<', now('UTC')->subDays((int) $days))
                    ->limit(1000);

                // Short batches keep locks and the transaction log small on large tables.
                $deleted = 0;

                do {
                    $deleted += $batch = $query->delete();
                } while ($batch === 1000);

                $this->components->twoColumnDetail($table, "{$deleted} deleted");
            }
        }

        return self::SUCCESS;
    }
}
