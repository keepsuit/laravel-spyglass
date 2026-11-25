<?php

namespace Keepsuit\Spyglass\Support;

use Throwable;

/**
 * @internal
 */
final class ExceptionFingerprint
{
    /**
     * Groups occurrences by class and throw site. The message is left out because it often embeds ids.
     */
    public static function for(Throwable $exception): string
    {
        $file = str_replace('\\', '/', $exception->getFile());
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        if (str_starts_with($file, $base)) {
            $file = substr($file, strlen($base));
        }

        return sha1($exception::class.'|'.$file.'|'.$exception->getLine());
    }
}
