<?php

namespace Keepsuit\Spyglass\Commands;

use Illuminate\Console\Command;

class SpyglassCommand extends Command
{
    public $signature = 'laravel-spyglass';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
