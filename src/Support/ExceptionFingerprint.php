<?php

namespace Keepsuit\Spyglass\Support;

use Throwable;

/**
 * @internal
 */
final class ExceptionFingerprint
{
    /**
     * Groups occurrences by class and location. The message is left out because it often embeds ids.
     */
    public static function for(Throwable $exception): string
    {
        [$file, $line] = self::location($exception);

        return sha1($exception::class.'|'.$file.'|'.$line);
    }

    /**
     * The throw site, or the first application frame when the exception comes from vendor code
     * (`throw_if()`, query errors, ...), relative to the base path so it does not depend on the deploy path.
     *
     * @return array{string, int}
     */
    public static function location(Throwable $exception): array
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        foreach ([['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()] as $frame) {
            $file = str_replace('\\', '/', $frame['file'] ?? '');

            if (str_starts_with($file, $base) && ! str_starts_with($file, $base.'vendor/')) {
                return [substr($file, strlen($base)), $frame['line'] ?? 0];
            }
        }

        $file = str_replace('\\', '/', $exception->getFile());

        return [str_starts_with($file, $base) ? substr($file, strlen($base)) : $file, $exception->getLine()];
    }
}
