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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Facades\Spyglass;
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
    private const int MAX_BYTES = 4096;

    /**
     * The job being processed by a worker, with the Context captured before tenancy can revert it.
     *
     * @var array{source: string, source_id: ?string, scope: ?string, context: ?string}|null
     */
    private ?array $job = null;

    /**
     * The job each exception was thrown by: the worker reports it after the attempt ended.
     *
     * @var WeakMap<Throwable, array{source: string, source_id: ?string, scope: ?string, context: ?string}>
     */
    private WeakMap $jobs;

    public function __construct()
    {
        $this->jobs = new WeakMap;
    }

    public function register(): void
    {
        // Sync jobs run inside the dispatching request or command, which is their source.
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            if (! $event->job instanceof SyncJob) {
                $this->job = ['source' => JobName::from($event->job->payload()), 'source_id' => $event->job->uuid(), ...Spyglass::captureContext()];
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
     * The source of an entry recorded now, or of `$exception` when it is reported after its job ended.
     *
     * @return array{source_type: string, source: ?string, source_id: ?string, route: ?string, scope: ?string, context: ?string}
     */
    public function current(?Throwable $exception = null): array
    {
        $job = ($exception === null ? null : $this->jobs[$exception] ?? null) ?? $this->job;

        if ($job !== null) {
            // The snapshot covers tenancy reverting the context before the failure is reported, not a job that switched tenant.
            $current = Spyglass::captureContext();

            return ['source_type' => 'job', 'route' => null, ...$job, ...($current['scope'] !== null ? $current : [])];
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
