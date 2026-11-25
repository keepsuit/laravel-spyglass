<?php

namespace Keepsuit\Spyglass\Modules\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Module;
use Keepsuit\Spyglass\Support\ExceptionFingerprint;
use Symfony\Component\Console\Input\ArgvInput;
use Throwable;
use WeakMap;

class ExceptionsModule extends Module
{
    public const string GROUPS = 'spyglass_exception_groups';

    public const string OCCURRENCES = 'spyglass_exception_occurrences';

    protected const int MAX_FRAMES = 50;

    protected const int MAX_BYTES = 4096;

    /**
     * Static because `Spyglass::modules()` builds a new instance on every call.
     *
     * @var WeakMap<Throwable, true>|null
     */
    protected static ?WeakMap $recorded = null;

    /**
     * The job being processed by a worker, with the Context captured before tenancy can revert it:
     * the worker reports the job's exception after the attempt ends.
     *
     * @var array{source: string, scope: ?string, context: ?string}|null
     */
    protected ?array $job = null;

    /**
     * Whether the exception was recorded in this process, so its log entry can be left out.
     */
    public static function recorded(Throwable $e): bool
    {
        return isset(static::$recorded[$e]);
    }

    public function boot(): void
    {
        /** @var ExceptionHandler $handler Larastan infers the handler bound where it runs. */
        $handler = app(ExceptionHandler::class);

        // A callback, not an event, so `dontReport` and `shouldReport` apply. It returns nothing, so reporting goes on.
        if ($handler instanceof Handler) {
            $handler->reportable(function (Throwable $e): void {
                $this->record($e);
            });
        }

        // Sync jobs run inside the dispatching request or command, which is their source.
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            if (! $event->job instanceof SyncJob) {
                $this->job = ['source' => $event->job->resolveName(), ...Spyglass::captureContext()];
            }
        });
        Event::listen(Looping::class, fn () => $this->job = null);
    }

    protected function record(Throwable $e): void
    {
        if (Spyglass::isIgnoring() || static::recorded($e)) {
            return;
        }

        static::$recorded ??= new WeakMap;
        static::$recorded[$e] = true;

        try {
            $fingerprint = ExceptionFingerprint::for($e);
            $now = Date::now('UTC')->toDateTimeString();
            $message = $this->truncate($e->getMessage());

            Spyglass::upsert(
                self::GROUPS,
                ['fingerprint' => $fingerprint],
                values: ['class' => $e::class, 'message' => $message, 'file' => $this->relative($e->getFile()), 'line' => $e->getLine(), 'resolved_at' => null],
                increment: ['count' => 1],
                max: ['last_seen_at' => $now],
                min: ['first_seen_at' => $now],
            );

            if (! $this->acquireOccurrenceSlot($fingerprint)) {
                return;
            }

            Spyglass::insert(self::OCCURRENCES, [
                'fingerprint' => $fingerprint,
                'occurred_at' => $now,
                'message' => $message,
                'trace' => json_encode($this->trace($e), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'user_id' => $this->userId(),
                ...$this->source(),
            ]);
        } catch (Throwable $failure) {
            Spyglass::report('Spyglass failed to record an exception', $failure);
        }
    }

    /**
     * At most one stored occurrence per minute and fingerprint, so a burst cannot flood the table.
     */
    protected function acquireOccurrenceSlot(string $fingerprint): bool
    {
        try {
            return Spyglass::cache()->add("spyglass:exception:{$fingerprint}", true, 60);
        } catch (Throwable $e) {
            Spyglass::report('Spyglass failed to throttle an exception', $e);

            return true;
        }
    }

    /**
     * Only a user the guard already holds: resolving one would query the database on every exception.
     */
    protected function userId(): ?string
    {
        return rescue(fn () => Auth::hasUser() ? (string) Auth::id() : null, report: false);
    }

    /**
     * @return array{source_type: string, source: ?string, route: ?string, scope?: ?string, context?: ?string}
     */
    protected function source(): array
    {
        if ($this->job !== null) {
            return ['source_type' => 'job', 'route' => null, ...$this->job];
        }

        $kernel = app()->resolved(HttpKernelContract::class) ? app(HttpKernelContract::class) : null;

        // Not `CommandStarting`: Laravel skips it in tests, and fires `CommandFinished` before an uncaught exception is reported.
        if (! $kernel instanceof HttpKernel || $kernel->requestStartedAt() === null) {
            return ['source_type' => 'command', 'source' => (new ArgvInput)->getFirstArgument(), 'route' => null];
        }

        /** @var Request $request */
        $request = app('request');
        $route = $request->route();

        return [
            'source_type' => 'request',
            'source' => $this->truncate($request->getMethod().' '.$request->getPathInfo().$this->maskedQuery($request)),
            'route' => is_object($route) ? $route->getName() : null,
        ];
    }

    /**
     * Query string values can hold tokens and personal data: only allowlisted keys keep theirs.
     */
    protected function maskedQuery(Request $request): string
    {
        $query = $request->getQueryString();

        if ($query === null) {
            return '';
        }

        $allowed = (array) config('spyglass.modules.exceptions.query_allowlist', []);

        return '?'.collect(explode('&', $query))
            ->map(function (string $pair) use ($allowed) {
                $key = Str::before($pair, '=');

                return in_array(Str::before(urldecode($key), '['), $allowed, true) ? $pair : "{$key}=***";
            })
            ->implode('&');
    }

    /**
     * Location and callee of each frame. Arguments are left out: they can hold anything.
     *
     * @return list<array{file: ?string, line: ?int, function: string}>
     */
    protected function trace(Throwable $e): array
    {
        return array_map(fn (array $frame) => [
            'file' => isset($frame['file']) ? mb_strcut($this->relative($frame['file']), 0, 255) : null,
            'line' => $frame['line'] ?? null,
            'function' => mb_strcut(($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'], 0, 255),
        ], array_slice($e->getTrace(), 0, self::MAX_FRAMES));
    }

    protected function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /**
     * Invalid UTF-8 would make PostgreSQL reject the whole batch.
     */
    protected function truncate(string $value): string
    {
        return mb_strcut(mb_scrub($value, 'UTF-8'), 0, self::MAX_BYTES, 'UTF-8');
    }

    public function prunable(): array
    {
        return [
            self::OCCURRENCES => ['column' => 'occurred_at', 'retention' => 'occurrences'],
            // Once its last occurrence is past retention, a group has none left to show.
            self::GROUPS => ['column' => 'last_seen_at', 'retention' => 'occurrences'],
        ];
    }
}
