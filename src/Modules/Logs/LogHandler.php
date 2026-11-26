<?php

namespace Keepsuit\Spyglass\Modules\Logs;

use Illuminate\Support\Facades\Date;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;
use Keepsuit\Spyglass\Modules\Exceptions\Throttle;
use Keepsuit\Spyglass\Support\Sources;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Always lets the record bubble to the other handlers of the stack.
 */
class LogHandler extends AbstractHandler
{
    protected const int MAX_BYTES = 4096;

    /** Entries stored per request, job, scheduled task or minute, so distinct messages cannot flood the table either. */
    protected const int MAX_ENTRIES = 100;

    protected int $stored = 0;

    /** Restarts the cap in processes that never start a new lifecycle. */
    protected int $minute = 0;

    protected Throttle $throttle;

    public function __construct(int|string|Level $level)
    {
        parent::__construct($level);

        $this->throttle = new Throttle;
    }

    public function startLifecycle(): void
    {
        $this->stored = 0;
    }

    public function handle(LogRecord $record): bool
    {
        if (($minute = intdiv(Date::now()->getTimestamp(), 60)) !== $this->minute) {
            $this->minute = $minute;
            $this->stored = 0;
        }

        // Spyglass logs its own failures while ignoring: they reach the other channels only.
        if (! $this->isHandling($record) || Spyglass::isIgnoring() || $this->stored >= self::MAX_ENTRIES) {
            return false;
        }

        $exception = $record->context['exception'] ?? null;

        // Laravel logs a reported exception right after the exceptions module recorded it.
        if ($exception instanceof Throwable && ExceptionsModule::recorded($exception)) {
            return false;
        }

        // Guarded: the cache, the context resolver and objects in the record context run application code that can log in turn.
        $result = Spyglass::ignore(function () use ($record, $exception) {
            try {
                $exception = $exception instanceof Throwable ? $exception : null;
                $source = app(Sources::class)->current($exception);
                // Log entries keep no route name.
                unset($source['route']);

                // A message can embed the exception's, as Laravel's log of a reported exception does: a query error's carries the bindings.
                $message = $this->truncate($exception !== null && $exception->getMessage() !== ''
                    ? str_replace($exception->getMessage(), ExceptionsModule::safeMessage($exception), $record->message)
                    : $record->message);

                // Per source too: a frequent one must not hide the entries of a task or a job.
                $stored = $this->throttle->acquire('spyglass:log:'.sha1(implode('|', [$record->level->value, $record->message, $source['scope'], $source['source_type'], Sources::name($source)])));

                return [
                    'level' => $record->level->toPsrLogLevel(),
                    'message' => $message,
                    'source' => $source,
                    'stored' => $stored,
                    'log_context' => ! $stored || $record->context === [] ? null : $this->truncate($this->encode($record->context)),
                ];
            } catch (Throwable $e) {
                Spyglass::report('Spyglass failed to record a log entry', $e);

                return null;
            }
        });

        if ($result === null) {
            return false;
        }

        // Counted even when throttled, so the sources of a message are exact.
        app(Sources::class)->count('log', LogsModule::signature($result['level'], $result['message']), $result['source']);

        if ($result['stored']) {
            $this->stored++;
            Spyglass::insert(LogsModule::ENTRIES, [
                'level' => $result['level'],
                'message' => $result['message'],
                'log_context' => $result['log_context'],
                'logged_at' => Date::now('UTC')->toDateTimeString(),
                ...$result['source'],
            ]);
        }

        return false;
    }

    /**
     * @param  array<mixed>  $context
     */
    protected function encode(array $context): string
    {
        $context = array_map(fn (mixed $value) => $value instanceof Throwable
            ? $value::class.': '.ExceptionsModule::safeMessage($value).' at '.$value->getFile().':'.$value->getLine()
            : $value, $context);

        return (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Invalid UTF-8 and NUL bytes would make PostgreSQL reject the whole batch.
     */
    protected function truncate(string $value): string
    {
        return mb_strcut(mb_scrub(str_replace("\0", '', $value), 'UTF-8'), 0, self::MAX_BYTES, 'UTF-8');
    }
}
