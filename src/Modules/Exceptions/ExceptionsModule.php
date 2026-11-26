<?php

namespace Keepsuit\Spyglass\Modules\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Routing\Route;
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
     * The job being processed by a worker, with the Context captured before tenancy can revert it.
     *
     * @var array{source: string, scope: ?string, context: ?string}|null
     */
    protected ?array $job = null;

    /**
     * The job each exception was thrown by: the worker reports it after the attempt ended.
     *
     * @var WeakMap<Throwable, array{source: string, scope: ?string, context: ?string}>
     */
    protected WeakMap $jobs;

    /** @var WeakMap<object, true> */
    protected WeakMap $handlers;

    protected Throttle $throttle;

    public function __construct()
    {
        $this->handlers = new WeakMap;
        $this->jobs = new WeakMap;
        $this->throttle = new Throttle;
    }

    /**
     * Whether the exception was recorded in this process, so its log entry can be left out.
     */
    public static function recorded(Throwable $e): bool
    {
        return isset(static::$recorded[$e]);
    }

    public function boot(): void
    {
        app()->afterResolving(ExceptionHandler::class, fn (object $handler) => $this->register($handler));

        // Also a handler swapped with `instance()`, which fires no resolving callback. Resolves the bound one now.
        $handler = app()->rebinding(ExceptionHandler::class, fn ($app, object $handler) => $this->register($handler));

        if (is_object($handler)) {
            $this->register($handler);
        }

        // Sync jobs run inside the dispatching request or command, which is their source.
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            if (! $event->job instanceof SyncJob) {
                $this->job = ['source' => $event->job->resolveName(), ...Spyglass::captureContext()];
            }
        });
        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) {
            if ($this->job !== null && ! $event->job instanceof SyncJob) {
                $this->jobs[$event->exception] = $this->job;
            }
        });
        // `queue:work --once` never loops, so the job must not outlive its attempt.
        Event::listen(JobAttempted::class, function (JobAttempted $event) {
            if (! $event->job instanceof SyncJob) {
                $this->job = null;
            }
        });
    }

    /**
     * A callback, not an event, so `dontReport` and `shouldReport` apply. It returns nothing, so reporting goes on.
     * Not `instanceof Handler`: decorators such as Collision's console handler forward `reportable()`, some through `__call()`.
     */
    protected function register(object $handler): void
    {
        if (isset($this->handlers[$handler]) || ! is_callable([$handler, 'reportable'])) {
            return;
        }

        $this->handlers[$handler] = true;
        $handler->reportable(function (Throwable $e): void {
            $this->record($e);
        });
    }

    protected function record(Throwable $e): void
    {
        if (Spyglass::isIgnoring() || static::recorded($e)) {
            return;
        }

        static::$recorded ??= new WeakMap;
        static::$recorded[$e] = true;

        // Guarded: the cache, the auth guard and the context resolver run application code that can report in turn.
        $entry = Spyglass::ignore(function () use ($e) {
            try {
                $fingerprint = ExceptionFingerprint::for($e);
                [$file, $line] = ExceptionFingerprint::location($e);
                $now = Date::now('UTC')->toDateTimeString();
                $message = $this->message($e);
                $source = $this->source($e);

                return [
                    'fingerprint' => $fingerprint,
                    'group' => ['class' => $this->truncate($e::class, 255), 'message' => $message, 'file' => $this->truncate($file), 'line' => $line],
                    'now' => $now,
                    'occurrence' => $this->throttle->acquire('spyglass:exception:'.sha1($fingerprint.'|'.$source['scope'])) ? [
                        'fingerprint' => $fingerprint,
                        'occurred_at' => $now,
                        'message' => $message,
                        'trace' => json_encode($this->trace($e), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                        'user_id' => $this->userId(),
                        ...$source,
                    ] : null,
                ];
            } catch (Throwable $failure) {
                Spyglass::report('Spyglass failed to record an exception', $failure);

                return null;
            }
        });

        if ($entry === null) {
            return;
        }

        // A group stays resolved until an occurrence newer than its resolution: see `ExceptionQueries`.
        Spyglass::upsert(
            self::GROUPS,
            ['fingerprint' => $entry['fingerprint']],
            values: $entry['group'],
            increment: ['count' => 1],
            max: ['last_seen_at' => $entry['now']],
            min: ['first_seen_at' => $entry['now']],
        );

        if ($entry['occurrence'] !== null) {
            Spyglass::insert(self::OCCURRENCES, $entry['occurrence']);
        }
    }

    protected function message(Throwable $e): string
    {
        return $this->truncate(self::safeMessage($e));
    }

    /**
     * The driver message and the bindings of a query error can hold personal data: only the SQL is kept.
     */
    public static function safeMessage(Throwable $e): string
    {
        return $e instanceof QueryException ? "SQLSTATE[{$e->getCode()}]: {$e->getSql()}" : $e->getMessage();
    }

    protected function userId(): ?string
    {
        try {
            $id = Auth::id();

            return $id === null ? null : (string) $id;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{source_type: string, source: ?string, route: ?string, scope: ?string, context: ?string}
     */
    protected function source(Throwable $e): array
    {
        $job = $this->jobs[$e] ?? $this->job;

        if ($job !== null) {
            return ['source_type' => 'job', 'route' => null, ...$job];
        }

        $kernel = app()->resolved(HttpKernelContract::class) ? app(HttpKernelContract::class) : null;

        // Not `CommandStarting`: Laravel skips it in tests, and fires `CommandFinished` before an uncaught exception is reported.
        if (! $kernel instanceof HttpKernel || $kernel->requestStartedAt() === null) {
            return ['source_type' => 'command', 'source' => (new ArgvInput)->getFirstArgument(), 'route' => null, ...Spyglass::captureContext()];
        }

        /** @var Request $request */
        $request = app('request');
        $route = $request->route();
        // The route URI, not the path: path parameters can be tokens or personal data.
        $path = $route instanceof Route ? '/'.ltrim($route->uri(), '/') : $request->getPathInfo();

        return [
            'source_type' => 'request',
            'source' => $this->truncate($request->getMethod().' '.$path.$this->maskedQuery($request)),
            'route' => $route instanceof Route ? $route->getName() : null,
            ...Spyglass::captureContext(),
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
            'file' => isset($frame['file']) ? $this->truncate($this->relative($frame['file']), 255) : null,
            'line' => $frame['line'] ?? null,
            'function' => $this->truncate(($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'], 255),
        ], array_slice($e->getTrace(), 0, self::MAX_FRAMES));
    }

    protected function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /**
     * Invalid UTF-8 and NUL bytes (anonymous class names have one) would make PostgreSQL reject the whole batch.
     */
    protected function truncate(string $value, int $bytes = self::MAX_BYTES): string
    {
        return mb_strcut(mb_scrub(str_replace("\0", '', $value), 'UTF-8'), 0, $bytes, 'UTF-8');
    }

    public function prunable(): array
    {
        return [
            self::OCCURRENCES => ['column' => 'occurred_at', 'retention' => 'occurrences'],
            // Once its last occurrence is past retention, a group has none left to show.
            self::GROUPS => ['column' => 'last_seen_at', 'retention' => 'occurrences'],
        ];
    }

    /**
     * Groups resolved longer ago than the `resolved_groups` retention, with no occurrence since.
     */
    public function prune(): array
    {
        $days = config('spyglass.modules.exceptions.retention.resolved_groups');

        if (! is_numeric($days)) {
            return [];
        }

        $query = Spyglass::connection()->table(self::GROUPS)
            ->where('resolved_at', '<', Date::now('UTC')->subDays((int) $days)->toDateTimeString())
            ->whereColumn('last_seen_at', '<=', 'resolved_at')
            ->limit(1000);

        $deleted = 0;

        do {
            $deleted += $batch = $query->delete();
        } while ($batch === 1000);

        return [self::GROUPS => $deleted];
    }
}
