<?php

namespace Keepsuit\Spyglass\Modules\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Module;
use Keepsuit\Spyglass\Support\ExceptionFingerprint;
use Keepsuit\Spyglass\Support\Sources;
use Keepsuit\Spyglass\Support\Text;
use PDOException;
use Throwable;
use WeakMap;

class ExceptionsModule extends Module
{
    public const string GROUPS = Sources::GROUPS;

    public const string OCCURRENCES = Sources::ENTRIES;

    protected const int MAX_FRAMES = 50;

    /**
     * Static because `Spyglass::modules()` builds a new instance on every call.
     *
     * @var WeakMap<Throwable, true>|null
     */
    protected static ?WeakMap $recorded = null;

    /** @var WeakMap<object, true> */
    protected WeakMap $handlers;

    protected Throttle $throttle;

    public function __construct()
    {
        $this->handlers = new WeakMap;
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
                $now = Date::now('UTC')->format('Y-m-d H:i:s.v');
                $message = $this->message($e);
                $source = app(Sources::class)->current($e);

                return [
                    'fingerprint' => $fingerprint,
                    'source' => $source,
                    'group' => ['class' => Text::clip($e::class, 255), 'message' => $message, 'file' => Text::clip($file), 'line' => $line],
                    'now' => $now,
                    'occurrence' => $this->throttle->acquire('spyglass:exception:'.sha1($fingerprint.'|'.$source['scope'])) ? [
                        'kind' => 'exception',
                        'signature' => $fingerprint,
                        'recorded_at' => $now,
                        'message' => $message,
                        'body' => json_encode($this->trace($e), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                        ...$this->cause($e),
                        'user_id' => $this->userId(),
                        ...Arr::except($source, 'name'),
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
            ['kind' => 'exception', 'signature' => $entry['fingerprint'], 'source_type' => '', 'source_hash' => ''],
            values: $entry['group'],
            increment: ['count' => 1],
            max: ['last_seen_at' => $entry['now']],
            min: ['first_seen_at' => $entry['now']],
        );

        app(Sources::class)->count('exception', $entry['fingerprint'], $entry['source']);

        if ($entry['occurrence'] !== null) {
            Spyglass::insert(self::OCCURRENCES, $entry['occurrence']);
        }
    }

    /**
     * @return array{cause_class: ?string, cause_message: ?string}
     */
    protected function cause(Throwable $e): array
    {
        $cause = null;

        // Bounded: a chain can loop when an exception is set as its own previous through reflection. Not past a
        // query error: what it wraps is the driver error, whose message can hold the data.
        for ($previous = $e instanceof QueryException ? null : $e->getPrevious(), $depth = 0; $previous !== null && $depth < 50 && ! $cause instanceof QueryException; $previous = $previous->getPrevious(), $depth++) {
            $cause = $previous;
        }

        return $cause === null
            ? ['cause_class' => null, 'cause_message' => null]
            : ['cause_class' => Text::clip($cause::class, 255), 'cause_message' => $this->message($cause)];
    }

    protected function message(Throwable $e): string
    {
        return Text::clip(self::safeMessage($e));
    }

    /**
     * The driver message and the bindings of a query error can hold personal data: only the SQL is kept.
     */
    public static function safeMessage(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return "SQLSTATE[{$e->getCode()}]: {$e->getSql()}";
        }

        if ($e instanceof PDOException) {
            return "SQLSTATE[{$e->getCode()}]";
        }

        $message = $e->getMessage();

        // Wrappers such as `ViewException` copy the message of the database error they wrap.
        for ($previous = $e->getPrevious(), $depth = 0; $previous !== null && $depth < 50; $previous = $previous->getPrevious(), $depth++) {
            if (($previous instanceof QueryException || $previous instanceof PDOException) && $previous->getMessage() !== '') {
                $message = str_replace($previous->getMessage(), self::safeMessage($previous), $message);
            }
        }

        return $message;
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
     * Location and callee of each frame. Arguments are left out: they can hold anything.
     *
     * @return list<array{file: ?string, line: ?int, function: string}>
     */
    protected function trace(Throwable $e): array
    {
        return array_map(fn (array $frame) => [
            'file' => isset($frame['file']) ? Text::clip($this->relative($frame['file']), 255) : null,
            'line' => $frame['line'] ?? null,
            'function' => Text::clip(($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'], 255),
        ], array_slice($e->getTrace(), 0, self::MAX_FRAMES));
    }

    protected function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    public function prunable(): array
    {
        return [
            self::OCCURRENCES => ['column' => 'recorded_at', 'retention' => 'occurrences', 'kind' => 'exception'],
            // Once its last occurrence is past retention, a group has none left to show, nor do its sources.
            self::GROUPS => ['column' => 'last_seen_at', 'retention' => 'occurrences', 'kind' => 'exception'],
        ];
    }

    /**
     * Groups resolved longer ago than the `resolved_groups` retention, with no occurrence since, and their sources.
     */
    public function prune(): array
    {
        $days = config('spyglass.modules.exceptions.retention.resolved_groups');

        if (! is_numeric($days)) {
            return [];
        }

        $resolvedBefore = Date::now('UTC')->subDays((int) $days)->toDateTimeString();
        $resolved = fn (Builder $query) => $query->where('source_type', '')
            ->where('resolved_at', '<', $resolvedBefore)
            ->whereColumn('last_seen_at', '<=', 'resolved_at');
        $groups = fn () => Spyglass::connection()->table(self::GROUPS)->where('kind', 'exception');
        $deleted = 0;

        do {
            $fingerprints = $groups()->where($resolved)->limit(1000)->pluck('signature')->all();

            if ($fingerprints === []) {
                break;
            }

            // Checked again: an occurrence stored since the read reopens its group.
            $deleted += $groups()->whereIn('signature', $fingerprints)->where($resolved)->delete();
            $gone = array_diff($fingerprints, $groups()->whereIn('signature', $fingerprints)->where('source_type', '')->pluck('signature')->all());

            // A group recreated later must not inherit the counts of its sources.
            $deleted += $gone === [] ? 0 : $groups()->whereIn('signature', $gone)->where('source_type', '!=', '')->delete();
        } while (count($fingerprints) === 1000);

        return [self::GROUPS.' (exception, resolved)' => $deleted];
    }
}
