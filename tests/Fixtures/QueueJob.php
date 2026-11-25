<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use RuntimeException;

class QueueJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'succeed'|'throw'|'fail'|'release'|'sleep'|'forget-context'  $action
     */
    public function __construct(public string $action = 'succeed') {}

    public function handle(): void
    {
        if ($this->action === 'forget-context') {
            Context::forget('tenant');
        }

        match ($this->action) {
            'throw', 'forget-context' => throw new RuntimeException('Job failed'),
            'fail' => $this->fail(new RuntimeException('Failed manually')),
            'release' => $this->release(),
            'sleep' => sleep(3),
            default => null,
        };
    }
}
