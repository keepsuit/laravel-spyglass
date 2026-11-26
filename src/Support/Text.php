<?php

namespace Keepsuit\Spyglass\Support;

/**
 * @internal
 */
final class Text
{
    /**
     * Cut at a byte length, which also fits a column of that many characters. Invalid UTF-8 and NUL bytes
     * (anonymous class names have one) would make PostgreSQL reject the whole batch.
     */
    public static function clip(string $value, int $bytes = 4096): string
    {
        return mb_strcut(mb_scrub(str_replace("\0", '', $value), 'UTF-8'), 0, $bytes, 'UTF-8');
    }
}
