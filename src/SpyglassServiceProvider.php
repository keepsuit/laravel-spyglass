<?php

namespace Keepsuit\Spyglass;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Keepsuit\Spyglass\Commands\PruneCommand;
use Keepsuit\Spyglass\Http\Middleware\Authorize;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SpyglassServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-spyglass')
            ->hasConfigFile()
            ->hasMigrations([
                'create_spyglass_schedule_runs_table',
                'create_spyglass_queue_tables',
            ])
            ->hasCommand(PruneCommand::class);

        $package->hasMigrations([
            'create_spyglass_exceptions_tables',
            'create_spyglass_logs_table',
        ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Spyglass::class, function ($app) {
            $config = $app['config'];
            $connection = $config->get('spyglass.storage.connection') ?? $config->get('database.default');

            // A connection of its own keeps writes out of the application's transactions: a rollback
            // or a deadlock on either side must not affect the other. SQLite allows a single writer,
            // so a second connection would wait on the application's own locks.
            if ($config->get("database.connections.{$connection}.driver") !== 'sqlite') {
                $config->set('database.connections.spyglass', $config->get("database.connections.{$connection}"));
                $connection = 'spyglass';
            }

            return new Spyglass(
                connection: $connection,
                cacheStore: $config->get('spyglass.cache.store') ?? $config->get('cache.default'),
                enabled: (bool) $config->get('spyglass.enabled'),
            );
        });
    }

    public function packageBooted(): void
    {
        // Resolving here pins the connection and cache store names before anything
        // (e.g. tenancy) can switch the application defaults at runtime.
        $spyglass = $this->app->make(Spyglass::class);

        // Even when disabled: a `stack` listing an unknown driver sends every record to the emergency logger
        // instead of its other channels. The logs module replaces it when enabled.
        $this->callAfterResolving('log', function (LogManager $log) {
            $log->extend('spyglass', fn () => new Logger('spyglass', [new NullHandler]));
        });

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
     * Flush at the end of each request, job, console command, scheduled task and worker loop, and at the
     * start of each job, scheduled task and Octane request so nothing is carried over from a previous lifecycle.
     */
    protected function registerLifecycle(Spyglass $spyglass): void
    {
        $flush = fn () => $spyglass->flush();

        // Also ends console commands. Not `CommandFinished`: `Artisan::call()` fires it inside the
        // calling request or job, whose lifecycle still owns the entries.
        $this->app->terminating($flush);

        // After Laravel's own shutdown handler, which reports fatal errors, and after `terminating` callbacks
        // registered later, such as `dispatch()->afterResponse()`.
        register_shutdown_function($flush);

        Event::listen([
            // The worker reports a job's exception after `JobAttempted`.
            Looping::class,
            WorkerStopping::class,
            ScheduledTaskStarting::class,
            ScheduledTaskFinished::class,
            ScheduledTaskFailed::class,
            ScheduledTaskSkipped::class,
            'Laravel\Octane\Events\RequestReceived',
            'Laravel\Octane\Events\RequestTerminated',
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
