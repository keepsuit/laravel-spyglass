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
     * @param  'succeed'|'throw'|'fail'|'release'|'sleep'|'pause'|'delete-throw'|'dispatch'|'forget-context'  $action
     */
    public function __construct(public string $action = 'succeed', public ?string $displayName = null) {}

    public function handle(): void
    {
        if ($this->action === 'forget-context') {
            Context::forget('tenant');
        }

        if ($this->action === 'delete-throw') {
            $this->delete();
        }

        match ($this->action) {
            'throw', 'forget-context', 'delete-throw' => throw new RuntimeException('Job failed'),
            'fail' => $this->fail(new RuntimeException('Failed manually')),
            'release' => $this->release(),
            'sleep' => sleep(3),
            'pause' => usleep(50_000),
            'dispatch' => self::dispatch()->onQueue('other'),
            default => null,
        };
    }

    public function displayName(): string
    {
        return $this->displayName ?? static::class;
    }
}
