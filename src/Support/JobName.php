<?php

namespace Keepsuit\Spyglass\Support;

/**
 * @internal
 */
final class JobName
{
    /**
     * The job class, since a custom `displayName()` may embed ids. Framework wrappers (queued listeners,
     * mailables, notifications, ...) are named after the class they wrap, as their display name is.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function from(array $payload): string
    {
        $class = $payload['data']['commandName'] ?? null;

        return is_string($class) && ! str_starts_with($class, 'Illuminate\\')
            ? $class
            : (string) ($payload['displayName'] ?? $class ?? $payload['job'] ?? 'unknown');
    }
}
