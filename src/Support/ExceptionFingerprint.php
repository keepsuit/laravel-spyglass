<?php

namespace Keepsuit\Spyglass\Support;

use Laravel\SerializableClosure\Support\ClosureStream;
use Throwable;

/**
 * @internal
 */
final class ExceptionFingerprint
{
    /**
     * Groups occurrences by class and the function that threw, not the line: editing code above the throw
     * site must not split a group on the next deploy. The message is left out because it often embeds ids.
     */
    public static function for(Throwable $exception): string
    {
        [$file, $line, $function] = self::frame($exception);

        // A closure has no stable name (since PHP 8.4 it embeds its absolute path): its line identifies it.
        $site = $function === null || str_contains($function, '{closure') ? $line : $function;

        return sha1($exception::class.'|'.$file.'|'.$site);
    }

    /**
     * The throw site, or the first application frame when the exception comes from vendor code
     * (`throw_if()`, query errors, ...), relative to the base path so it does not depend on the deploy path.
     *
     * @return array{string, int}
     */
    public static function location(Throwable $exception): array
    {
        [$file, $line] = self::frame($exception);

        return [$file, $line];
    }

    /**
     * @return array{string, int, ?string} file, line and the function containing that line
     */
    protected static function frame(Throwable $exception): array
    {
        $trace = $exception->getTrace();

        // Each trace frame names the function called at its line, so the one containing a line is the next frame's.
        foreach ([['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$trace] as $i => $frame) {
            $file = str_replace('\\', '/', $frame['file'] ?? '');

            // A queued closure runs from its code alone, whose line 2 is the closure's first line.
            if (str_starts_with($file, ClosureStream::STREAM_PROTO.'://') && ($closure = app(Sources::class)->closure($exception)) !== null) {
                return [$closure[0], $closure[1] + ($frame['line'] ?? 2) - 2, self::function($trace[$i] ?? null)];
            }

            if (($path = self::applicationPath($file)) !== null) {
                return [$path, $frame['line'] ?? 0, self::function($trace[$i] ?? null)];
            }
        }

        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $file = str_replace('\\', '/', $exception->getFile());

        return [str_starts_with($file, $base) ? substr($file, strlen($base)) : $file, $exception->getLine(), self::function($trace[0] ?? null)];
    }

    /**
     * `$file` relative to the base path, or null outside the application or in `vendor/`.
     */
    public static function applicationPath(string $file): ?string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $base) && ! str_starts_with($file, $base.'vendor/') ? substr($file, strlen($base)) : null;
    }

    /**
     * @param  array<string, mixed>|null  $frame
     */
    protected static function function(?array $frame): ?string
    {
        return isset($frame['function']) ? ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'] : null;
    }
}
