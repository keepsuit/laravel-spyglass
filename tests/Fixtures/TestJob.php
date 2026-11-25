<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Keepsuit\Spyglass\Facades\Spyglass;
use RuntimeException;

class TestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $name, public bool $fail = false) {}

    public function handle(): void
    {
        Spyglass::insert('spyglass_test_entries', ['name' => $this->name]);

        if ($this->fail) {
            throw new RuntimeException('Job failed');
        }
    }
}
