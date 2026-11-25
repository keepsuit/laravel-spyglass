<?php

namespace Keepsuit\Spyglass;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Keepsuit\Spyglass\Commands\PruneCommand;
use Keepsuit\Spyglass\Http\Middleware\Authorize;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SpyglassServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-spyglass')
            ->hasConfigFile()
            ->hasCommand(PruneCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Spyglass::class, fn ($app) => new Spyglass(
            connection: $app['config']->get('spyglass.storage.connection') ?? $app['config']->get('database.default'),
            cacheStore: $app['config']->get('spyglass.cache.store') ?? $app['config']->get('cache.default'),
            enabled: (bool) $app['config']->get('spyglass.enabled'),
        ));
    }

    public function packageBooted(): void
    {
        // Resolving here pins the connection and cache store names before anything
        // (e.g. tenancy) can switch the application defaults at runtime.
        $spyglass = $this->app->make(Spyglass::class);

        if (! config('spyglass.enabled')) {
            return;
        }

        // Modules first, so their listeners on lifecycle-ending events run before the flush.
        foreach ($spyglass->modules() as $module) {
            $module->boot();
        }

        $this->registerLifecycle($spyglass);
        $this->registerAuthorization();
        $this->registerRoutes();

        if (config('spyglass.prune.schedule')) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command(PruneCommand::class)->daily();
            });
        }
    }

    /**
     * Flush at the end of each request, job, command and scheduled task, and at the start of each
     * job, scheduled task and Octane request so nothing is carried over from a previous lifecycle.
     */
    protected function registerLifecycle(Spyglass $spyglass): void
    {
        $flush = fn () => $spyglass->flush();

        $this->app->terminating($flush);

        Event::listen([
            WorkerStopping::class,
            CommandFinished::class,
            ScheduledTaskStarting::class,
            ScheduledTaskFinished::class,
            ScheduledTaskFailed::class,
            ScheduledTaskSkipped::class,
            'Laravel\Octane\Events\RequestReceived',
        ], $flush);

        // Sync jobs run inside the dispatching lifecycle, which flushes them.
        Event::listen([JobProcessing::class, JobAttempted::class], function (JobProcessing|JobAttempted $event) use ($spyglass) {
            if (! $event->job instanceof SyncJob) {
                $spyglass->flush();
            }
        });
    }

    protected function registerAuthorization(): void
    {
        $this->callAfterResolving(Gate::class, function (Gate $gate) {
            if (! $gate->has('viewSpyglass')) {
                $gate->define('viewSpyglass', fn ($user = null) => $this->app->environment('local'));
            }
        });
    }

    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::prefix(config('spyglass.path'))
            ->middleware([...config('spyglass.middleware', []), Authorize::class])
            ->name('spyglass.')
            ->group(function () {
                Route::get('/', fn () => response()->noContent())->name('index');
            });
    }
}
