<?php

namespace Keepsuit\Spyglass;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Commands\PruneCommand;
use Keepsuit\Spyglass\Http\Controllers\ExceptionsController;
use Keepsuit\Spyglass\Http\Controllers\HealthController;
use Keepsuit\Spyglass\Http\Controllers\LogsController;
use Keepsuit\Spyglass\Http\Controllers\QueueController;
use Keepsuit\Spyglass\Http\Controllers\ScheduleController;
use Keepsuit\Spyglass\Http\Middleware\AcceptJson;
use Keepsuit\Spyglass\Http\Middleware\Authorize;
use Keepsuit\Spyglass\Http\Middleware\EnsureModuleEnabled;
use Keepsuit\Spyglass\Support\Sources;
use Monolog\Handler\NoopHandler;
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
            ->hasMigration('create_spyglass_tables')
            ->hasCommand(PruneCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Sources::class);

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
            // Noop, not Null: a NullHandler stops the record from reaching the channels after it in the stack.
            $log->extend('spyglass', fn () => new Logger('spyglass', [new NoopHandler]));
        });

        if (! config('spyglass.enabled')) {
            return;
        }

        // Before the modules, which read it in their listeners on the same events.
        $this->app->make(Sources::class)->register();

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

                // The class the `web` group uses, so the check runs once: Laravel 13 renamed it.
                $csrf = class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken';

                Route::prefix('api')->middleware(AcceptJson::class)->name('api.')->group(function () use ($csrf) {
                    Route::get('/', fn (Spyglass $spyglass) => ['modules' => array_map(
                        fn (string $module) => ['key' => $module, 'label' => Str::headline($module)],
                        array_keys($spyglass->modules()),
                    )])->name('modules');

                    Route::middleware(EnsureModuleEnabled::class.':schedule')->group(function () {
                        Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule');
                        Route::get('schedule/{task}', [ScheduleController::class, 'show'])->name('schedule.show');
                    });

                    Route::middleware(EnsureModuleEnabled::class.':queue')->group(function () use ($csrf) {
                        Route::get('queue', [QueueController::class, 'index'])->name('queue');
                        Route::get('queue/failed-jobs/{uuid}', [QueueController::class, 'show'])->name('queue.failed-jobs.show');

                        // Also when `spyglass.middleware` leaves out the `web` group, which normally checks it.
                        Route::middleware($csrf)->group(function () {
                            Route::post('queue/failed-jobs/{uuid}/retry', [QueueController::class, 'retry'])->name('queue.failed-jobs.retry');
                            Route::post('queue/failed-jobs/{uuid}/delete', [QueueController::class, 'delete'])->name('queue.failed-jobs.delete');
                        });
                    });

                    Route::middleware(EnsureModuleEnabled::class.':exceptions')->group(function () use ($csrf) {
                        Route::get('exceptions', [ExceptionsController::class, 'index'])->name('exceptions');
                        Route::get('exceptions/{fingerprint}', [ExceptionsController::class, 'show'])->name('exceptions.show');

                        Route::middleware($csrf)->group(function () {
                            Route::post('exceptions/{fingerprint}/resolve', [ExceptionsController::class, 'resolve'])->name('exceptions.resolve');
                            Route::post('exceptions/{fingerprint}/reopen', [ExceptionsController::class, 'reopen'])->name('exceptions.reopen');
                        });
                    });

                    Route::middleware(EnsureModuleEnabled::class.':logs')->group(function () {
                        Route::get('logs', [LogsController::class, 'index'])->name('logs');
                        Route::get('logs/{id}', [LogsController::class, 'show'])->whereNumber('id')->name('logs.show');
                    });

                    Route::middleware(EnsureModuleEnabled::class.':health')->group(function () {
                        Route::get('health', [HealthController::class, 'index'])->name('health');
                    });
                });
            });
    }
}
