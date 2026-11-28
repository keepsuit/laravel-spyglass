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
        foreach ($spyglass->modules() as $module) {
            foreach ($module->prunable() as $table => $rule) {
                ['column' => $column, 'retention' => $retention] = $rule;
                $days = $module::config("retention.{$retention}");

                if (! is_numeric($days)) {
                    continue;
                }

                $query = $spyglass->connection()->table($table)
                    ->when($rule['kind'] ?? null, fn ($query, string $kind) => $query->where('kind', $kind))
                    ->where($column, '<', now('UTC')->subDays((int) $days))
                    ->limit(1000);

                // Short batches keep locks and the transaction log small on large tables.
                $deleted = 0;

                do {
                    $deleted += $batch = $query->delete();
                } while ($batch === 1000);

                $this->components->twoColumnDetail(isset($rule['kind']) ? "{$table} ({$rule['kind']})" : $table, "{$deleted} deleted");
            }

            foreach ($module->prune() as $table => $deleted) {
                $this->components->twoColumnDetail($table, "{$deleted} deleted");
            }
        }

        return self::SUCCESS;
    }
}
