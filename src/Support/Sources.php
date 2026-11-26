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
    /** Exception occurrences and log entries. */
    public const string ENTRIES = 'spyglass_entries';

    /** A total row per exception group (`source_type` ''), and a row per source of an exception group or log message. */
    public const string GROUPS = 'spyglass_groups';

    /** Hidden Context key carrying the source of a scheduled task to its background process. */
    public const string CONTEXT_SOURCE = 'spyglass_source';

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
                // Restored from the job's payload: a job dispatched by a task process carries the task.
                Context::forgetHidden(self::CONTEXT_SOURCE);
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
     * The current task run was not one, such as an overlap skip: entries keep the task, not the run.
     */
    public function forgetRun(): void
    {
        if ($this->task !== null) {
            $this->task['source_id'] = null;
        }
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
     * `name` is the source without what varies between its entries, such as a query string: to group by.
     *
     * @return array{source_type: string, source: ?string, source_id: ?string, route: ?string, scope: ?string, context: ?string, name: string}
     */
    public function current(?Throwable $exception = null): array
    {
        $source = $this->resolve($exception);

        return [
            ...$source,
            'source' => $source['source'] === null ? null : Text::clip($source['source']),
            'source_id' => $source['source_id'] === null ? null : Text::clip($source['source_id'], 64),
            'route' => $source['route'] === null ? null : Text::clip($source['route'], 255),
            'name' => $source['name'] ?? (string) $source['source'],
        ];
    }

    /**
     * @return array{source_type: string, source: ?string, source_id: ?string, route: ?string, scope: ?string, context: ?string, name?: string}
     */
    private function resolve(?Throwable $exception): array
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

        // Not cut at `?`: optional route parameters contain one.
        $name = Text::clip($request->getMethod().' /'.ltrim($route->uri(), '/'));

        return [
            'source_type' => 'request',
            'source' => Text::clip($name.$this->maskedQuery($request)),
            'source_id' => null,
            'route' => $route->getName(),
            'name' => $name,
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
     * @param  array{source_type: string, name: string}  $source
     */
    public function count(string $kind, string $signature, array $source): void
    {
        // Keyed by digest: a prefix that fits the index would merge long routes.
        Spyglass::upsert(self::GROUPS, [
            'kind' => $kind,
            'signature' => $signature,
            'source_type' => $source['source_type'],
            'source_hash' => sha1($source['name']),
        ], values: [
            // Scrubbed: PostgreSQL rejects invalid UTF-8 and NUL bytes, and with them the whole batch.
            'source' => Text::clip($source['name']),
        ], increment: ['count' => 1], max: ['last_seen_at' => Date::now('UTC')->format('Y-m-d H:i:s.v')]);
    }

    /**
     * The sources of `$signature`, most frequent first.
     *
     * @param  'exception'|'log'  $kind
     * @return array<int, stdClass> with `source_type`, `source`, `count` and `last_seen_at`
     */
    public function of(string $kind, string $signature, int $limit = 100): array
    {
        return Spyglass::connection()->table(self::GROUPS)
            ->where('kind', $kind)
            ->where('signature', $signature)
            ->where('source_type', '!=', '')
            ->orderByDesc('count')
            ->orderBy('source')
            ->limit(max(1, min($limit, 1000)))
            ->get(['source_type', 'source', 'count', 'last_seen_at'])
            ->all();
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
}
