<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;

class QueuedListener implements ShouldQueue
{
    public function handle(object $event): void {}
}
