<?php

namespace Keepsuit\Spyglass\Modules\Logs;

use Illuminate\Support\Facades\Date;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;
use Monolog\Handler\AbstractHandler;
use Monolog\LogRecord;
use Throwable;

/**
 * Always lets the record bubble to the other handlers of the stack.
 */
class LogHandler extends AbstractHandler
{
    protected const int MAX_BYTES = 4096;

    public function handle(LogRecord $record): bool
    {
        // Spyglass logs its own failures while ignoring: they reach the other channels only.
        if (! $this->isHandling($record) || Spyglass::isIgnoring()) {
            return false;
        }

        $exception = $record->context['exception'] ?? null;

        // Laravel logs a reported exception right after the exceptions module recorded it.
        if ($exception instanceof Throwable && ExceptionsModule::recorded($exception)) {
            return false;
        }

        try {
            if (! $this->acquireSlot($record)) {
                return false;
            }

            Spyglass::insert(LogsModule::ENTRIES, [
                'level' => $record->level->toPsrLogLevel(),
                'channel' => mb_strcut($record->channel, 0, 64, 'UTF-8'),
                'message' => $this->truncate($record->message),
                'log_context' => $record->context === [] ? null : $this->truncate($this->encode($record->context)),
                'logged_at' => Date::now('UTC')->toDateTimeString(),
            ]);
        } catch (Throwable $e) {
            Spyglass::report('Spyglass failed to record a log entry', $e);
        }

        return false;
    }

    /**
     * At most one entry per minute for the same level and message, so a burst cannot flood the table.
     */
    protected function acquireSlot(LogRecord $record): bool
    {
        try {
            return Spyglass::cache()->add('spyglass:log:'.sha1($record->level->value.'|'.$record->message), true, 60);
        } catch (Throwable $e) {
            Spyglass::report('Spyglass failed to throttle a log entry', $e);

            return true;
        }
    }

    /**
     * @param  array<mixed>  $context
     */
    protected function encode(array $context): string
    {
        $context = array_map(fn (mixed $value) => $value instanceof Throwable
            ? $value::class.': '.$value->getMessage().' at '.$value->getFile().':'.$value->getLine()
            : $value, $context);

        return (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Invalid UTF-8 would make PostgreSQL reject the whole batch.
     */
    protected function truncate(string $value): string
    {
        return mb_strcut(mb_scrub($value, 'UTF-8'), 0, self::MAX_BYTES, 'UTF-8');
    }
}
