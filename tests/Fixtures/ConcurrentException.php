<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use RuntimeException;

/**
 * Built at the same site in every process, so they share one fingerprint.
 */
class ConcurrentException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Concurrent');
    }
}
