<?php

namespace Keepsuit\Spyglass\Support;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Facades\Spyglass;
use stdClass;
use Symfony\Component\Console\Input\ArgvInput;
use Throwable;
use WeakMap;

/**
 * What an entry comes from: the job, the request or the console command running it. Shared by the modules,
 * so each of them works whichever others are enabled.
 *
 * @internal
 */
final class Sources
{
    public const string TABLE = 'spyglass_sources';

    /** Hidden Context key carrying the source of a scheduled task to its background process. */
    public const string CONTEXT_SOURCE = 'spyglass_source';

    private const int MAX_BYTES = 4096;

    /**
     * The job being processed by a worker, with the Context captured before tenancy can revert it.
     *
     * @var array{source_type: string, source: string, source_id: ?string, scope: ?string, context: ?string}|null
     */
    private ?array $job = null;

    /**
     * The scheduled task running in this process.
     *
     * @var array{source_type: string, source: string, source_id: ?string, scope: ?string, context: ?string}|null
     */
    private ?array $task = null;

    /**
     * The job or task each exception was thrown by: both are reported after their end event.
     *
     * @var WeakMap<Throwable, array{source_type: string, source: string, source_id: ?string, scope: ?string, context: ?string}>
     */
    private WeakMap $thrown;

    public function __construct()
    {
        $this->thrown = new WeakMap;
    }

    public function register(): void
    {
        // Sync jobs run inside the dispatching request or command, which is their source.
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            if (! $event->job instanceof SyncJob) {
                $this->job = ['source_type' => 'job', 'source' => JobName::from($event->job->payload()), 'source_id' => $event->job->uuid(), ...Spyglass::captureContext()];
            }
        });
        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) {
            if ($this->job !== null && ! $event->job instanceof SyncJob) {
                $this->thrown[$event->exception] = $this->job;
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
     * Until `leaveTask()`, entries come from the scheduled task run `$id`.
     */
    public function enterTask(string $label, string $id): void
    {
        $this->task = ['source_type' => 'schedule', 'source' => $label, 'source_id' => $id, ...Spyglass::captureContext()];
    }

    /**
     * @param  Throwable|null  $exception  the exception that failed the task, which Laravel reports afterwards
     */
    public function leaveTask(?Throwable $exception = null): void
    {
        if ($exception !== null && $this->task !== null) {
            $this->thrown[$exception] = $this->task;
        }

        $this->task = null;
    }

    /**
     * The source of an entry recorded now, or of `$exception` when it is reported after its job or task ended.
     *
     * @return array{source_type: string, source: ?string, source_id: ?string, route: ?string, scope: ?string, context: ?string}
     */
    public function current(?Throwable $exception = null): array
    {
        // In memory before the hidden Context, which also travels into the jobs a background task dispatches.
        $source = ($exception === null ? null : $this->thrown[$exception] ?? null) ?? $this->job ?? $this->task ?? $this->background();

        if ($source !== null) {
            // The snapshot covers tenancy reverting the context before the failure is reported, not a job that switched tenant.
            $current = Spyglass::captureContext();

            return ['route' => null, ...$source, ...($current['scope'] !== null ? $current : [])];
        }

        $kernel = app()->resolved(HttpKernelContract::class) ? app(HttpKernelContract::class) : null;

        // Not `CommandStarting`: Laravel skips it in tests, and fires `CommandFinished` before an uncaught exception is reported.
        if (! $kernel instanceof HttpKernel || $kernel->requestStartedAt() === null) {
            return ['source_type' => 'command', 'source' => (new ArgvInput)->getFirstArgument(), 'source_id' => null, 'route' => null, ...Spyglass::captureContext()];
        }

        /** @var Request $request */
        $request = app('request');
        $route = $request->route();

        // The route URI, never the path: path parameters can be tokens or personal data. Before routing there is none.
        if (! $route instanceof Route) {
            return ['source_type' => 'request', 'source' => $request->getMethod().' (unrouted)', 'source_id' => null, 'route' => null, ...Spyglass::captureContext()];
        }

        return [
            'source_type' => 'request',
            'source' => $this->truncate($request->getMethod().' /'.ltrim($route->uri(), '/').$this->maskedQuery($request)),
            'source_id' => null,
            'route' => $route->getName(),
            ...Spyglass::captureContext(),
        ];
    }

    /**
     * The scheduled task a background process runs for, passed on by Laravel in the Context.
     *
     * @return array{source_type: string, source: string, source_id: ?string, scope: ?string, context: ?string}|null
     */
    private function background(): ?array
    {
        $source = Context::getHidden(self::CONTEXT_SOURCE);

        return is_array($source) && is_string($source['source'] ?? null)
            ? ['source_type' => 'schedule', 'source' => $source['source'], 'source_id' => is_string($source['source_id'] ?? null) ? $source['source_id'] : null, ...Spyglass::captureContext()]
            : null;
    }

    /**
     * Count `$source` for the exception group or log message `$signature`: the origins of each, exactly.
     *
     * @param  'exception'|'log'  $kind
     * @param  array{source_type: string, source: ?string}  $source
     */
    public function count(string $kind, string $signature, array $source): void
    {
        Spyglass::upsert(self::TABLE, [
            'kind' => $kind,
            'signature' => $signature,
            'source_type' => $source['source_type'],
            // Scrubbed: PostgreSQL rejects invalid UTF-8 and NUL bytes, and with them the whole batch.
            'source' => mb_substr($this->truncate(self::name($source)), 0, 191),
        ], increment: ['count' => 1], max: ['last_seen_at' => Date::now('UTC')->format('Y-m-d H:i:s.v')]);
    }

    /**
     * Rows of `$kind` last seen before `$days` days ago.
     *
     * @param  'exception'|'log'  $kind
     */
    public function prune(string $kind, mixed $days): int
    {
        if (! is_numeric($days)) {
            return 0;
        }

        $query = Spyglass::connection()->table(self::TABLE)
            ->where('kind', $kind)
            ->where('last_seen_at', '<', Date::now('UTC')->subDays((int) $days)->format('Y-m-d H:i:s.v'))
            ->limit(1000);
        $deleted = 0;

        // Short batches keep locks and the transaction log small on large tables.
        do {
            $deleted += $batch = $query->delete();
        } while ($batch === 1000);

        return $deleted;
    }

    /**
     * The sources of `$signature`, most frequent first.
     *
     * @param  'exception'|'log'  $kind
     * @return array<int, stdClass> with `source_type`, `source`, `count` and `last_seen_at`
     */
    public function of(string $kind, string $signature, int $limit = 100): array
    {
        return Spyglass::connection()->table(self::TABLE)
            ->where('kind', $kind)
            ->where('signature', $signature)
            ->orderByDesc('count')
            ->orderBy('source')
            ->limit(max(1, min($limit, 1000)))
            ->get(['source_type', 'source', 'count', 'last_seen_at'])
            ->all();
    }

    /**
     * The source without what varies between its entries, such as the query string of a request: to group by.
     *
     * @param  array{source_type: string, source: ?string}  $source
     */
    public static function name(array $source): string
    {
        return $source['source_type'] === 'request' ? Str::before((string) $source['source'], '?') : (string) $source['source'];
    }

    /**
     * Query string values can hold tokens and personal data: only allowlisted keys keep theirs.
     */
    private function maskedQuery(Request $request): string
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

    private function truncate(string $value): string
    {
        return mb_strcut(mb_scrub(str_replace("\0", '', $value), 'UTF-8'), 0, self::MAX_BYTES, 'UTF-8');
    }
}
