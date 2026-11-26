<?php

namespace Keepsuit\Spyglass\Modules\Logs;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;
use Keepsuit\Spyglass\Modules\Exceptions\Throttle;
use Keepsuit\Spyglass\Support\Sources;
use Keepsuit\Spyglass\Support\Text;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Always lets the record bubble to the other handlers of the stack.
 */
class LogHandler extends AbstractHandler
{
    /** Entries stored per request, job, scheduled task or minute, so distinct messages cannot flood the table either. */
    protected const int MAX_ENTRIES = 100;

    protected int $stored = 0;

    /** @var array<string, true> messages counted since the cap restarted */
    protected array $seen = [];

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
        $this->seen = [];
    }

    public function handle(LogRecord $record): bool
    {
        if (($minute = intdiv(Date::now()->getTimestamp(), 60)) !== $this->minute) {
            $this->minute = $minute;
            $this->startLifecycle();
        }

        // Spyglass logs its own failures while ignoring: they reach the other channels only.
        if (! $this->isHandling($record) || Spyglass::isIgnoring()) {
            return false;
        }

        $exception = $record->context['exception'] ?? null;

        // Laravel logs a reported exception right after the exceptions module recorded it.
        if ($exception instanceof Throwable && ExceptionsModule::recorded($exception)) {
            return false;
        }

        $capped = $this->stored >= self::MAX_ENTRIES;

        // Guarded: the cache, the context resolver and objects in the record context run application code that can log in turn.
        $result = Spyglass::ignore(function () use ($record, $exception, $capped) {
            try {
                $exception = $exception instanceof Throwable ? $exception : null;
                $source = app(Sources::class)->current($exception);

                // A message can embed the exception's, as Laravel's log of a reported exception does: a query error's carries the bindings.
                $message = Text::clip($exception !== null && $exception->getMessage() !== ''
                    ? str_replace($exception->getMessage(), ExceptionsModule::safeMessage($exception), $record->message)
                    : $record->message);
                $level = $record->level->toPsrLogLevel();
                $key = implode('|', [$level, $message, $source['scope'], $source['source_type'], $source['name']]);

                // Per source too: a frequent one must not hide the entries of a task or a job.
                $stored = ! $capped && $this->throttle->acquire('spyglass:log:'.sha1($key));

                return [
                    'key' => $key,
                    'level' => $level,
                    'message' => $message,
                    'source' => $source,
                    'stored' => $stored,
                    'log_context' => ! $stored || $record->context === [] ? null : Text::clip($this->encode($record->context)),
                ];
            } catch (Throwable $e) {
                Spyglass::report('Spyglass failed to record a log entry', $e);

                return null;
            }
        });

        // Past the cap only messages already counted are, so the sources keep exact counts and a bounded number of rows.
        if ($result === null || ($capped && ! isset($this->seen[$result['key']]))) {
            return false;
        }

        $this->seen[$result['key']] = true;
        // Counted even when throttled, so the sources of a message are exact.
        app(Sources::class)->count('log', LogsModule::signature($result['level'], $result['message']), $result['source']);

        if ($result['stored']) {
            $this->stored++;
            Spyglass::insert(LogsModule::ENTRIES, [
                'kind' => 'log',
                'signature' => LogsModule::signature($result['level'], $result['message']),
                'level' => $result['level'],
                'message' => $result['message'],
                'body' => $result['log_context'],
                'recorded_at' => Date::now('UTC')->format('Y-m-d H:i:s.v'),
                ...Arr::except($result['source'], ['route', 'name']),
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
}
