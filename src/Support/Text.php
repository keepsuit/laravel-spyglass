<?php

namespace Keepsuit\Spyglass\Support;

/**
 * @internal
 */
final class Text
{
    /**
     * Cut to fit a column of `$length` characters. Invalid UTF-8 and NUL bytes (anonymous class names have one)
     * would make PostgreSQL reject the whole batch.
     */
    public static function clip(string $value, int $length = 4096): string
    {
        return mb_substr(mb_scrub(str_replace("\0", '', $value), 'UTF-8'), 0, $length, 'UTF-8');
    }
}
