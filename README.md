# Spyglass: lightweight monitoring dashboard for Laravel

[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/keepsuit/laravel-spyglass/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/keepsuit/laravel-spyglass/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/keepsuit/laravel-spyglass/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/keepsuit/laravel-spyglass/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)

Spyglass is a single, lightweight dashboard that gives an overview of a Laravel application in production: scheduled tasks, queues, exceptions and logs. It is meant to replace Telescope, Horizon's dashboard and Pulse with something that is safe to keep enabled in production.

> [!WARNING]
> Spyglass is under active development and is not ready for use yet.

## Goals

- **Low overhead**: entries are buffered in memory and written in a single batch at the end of each request, job or command. Nothing is pushed to the queue, and storage failures never break the application.
- **Driver agnostic**: data is collected from Laravel events, so queue metrics are the same whether workers run with `queue:work` or Horizon. No Redis requirement.
- **Multi-tenant aware**: a configurable set of [Context](https://laravel.com/docs/context) keys is stored with every entry, and a resolver turns them into a filterable scope (for example the current tenant).
- **Everything optional**: every module can be enabled or disabled from the config file.

## Planned features

- **Schedule**: task list, last run, duration and outcome, next run, missed runs.
- **Queues**: processed, failed, released and timed out counters per queue and job class, pending/running/delayed sizes, failed jobs with retry and delete.
- **Exceptions**: grouped by fingerprint, with occurrences, first and last seen, resolve and reopen on regression.
- **Logs**: a `spyglass` log channel that stores `warning` and above.
- **Health**: database and cache connectivity, pending jobs per queue, debug mode in production, PHP, Laravel and package versions, deployed version (`APP_VERSION`).

Later: scheduler and queue heartbeats, alerts, on-demand request tracing, and an API for custom modules.

## Stored data

Spyglass never stores job payloads, query bindings or the driver message of query errors, and masks query string values unless allowed by the `query_allowlist` option of the exceptions module. It does store exception messages, the authenticated user id of an occurrence, log messages with their context and the configured Context keys: these can hold personal data, so treat the Spyglass tables like application logs when it comes to retention and access.

## Known limitations

- On Laravel 12, a background run skipped for overlap mid-execution shows as interrupted.
- On Laravel 12, a sub-minute task skipped for overlap right after a failed execution can be recorded as failed.
- On Laravel 12, a task skipped by `onOneServer` on the losing host emits no skip event.
- Concurrent runs appending to the same output file can capture each other's output.
- During a rolling deploy, a host still running the old code can republish the previous cron expression until it stops.
- Sub-minute slots are computed as aligned to the start of the minute.
- Successful runs of sub-minute tasks are not stored as runs, only the last one on the task: a crash during one shows as a missed run, not as an interrupted one.
- A background task whose `after()` callback throws is never closed by `schedule:finish`, so its run shows as interrupted.
- A task left out because the application was down is reported as missed when a task of the same tick brings the application back up.
- Identical tasks sharing a mutex but writing to different output files can store each other's output.
- The task list shows at most 1000 tasks.
- Scheduled tasks with the same command, description or closure line share one row, as one task at several times. Jobs of the same class are told apart only by constructor arguments kept in a property of the same name, and objects only by their class.
- The queue `Pending` card reads the backend now, cached for a few seconds: it has no history.
- `Bus::batch()` on the `database` queue driver does not fire `JobQueued`, so the `queued` counter undercounts batched jobs there.
- On SQLite, a worker timeout inside an open database transaction loses the counters of that attempt.
- A worker started directly on the target of a queue forward (`Queue::forward('a', 'b')`, worker on `b`) counts the attempts under `b`, while `queued` is counted under `a`.
- Exceptions with their own `report()` method, or stopped by an application `reportable` callback registered earlier (returning `false` or calling `stop()`), are not recorded.
- A manual `Log::error(..., ['exception' => $e])` after `report($e)` is skipped as already recorded.
- With the schedule module disabled, exceptions and logs of scheduled tasks have the `schedule:run` command as their source, not the task.
- The `source_id` of entries from a successful run of a sub-minute task points to no run, since those runs are not stored.
- With a `database` cache store, occurrences and log entries are throttled per process only, so several processes can store the same one in a minute: a cache query inside an open transaction could abort it on PostgreSQL.
- An exception thrown in the `catch()` callback of a queued closure is located in the closure's file, at a wrong line.
- Exceptions of queued closures run synchronously (`sync` driver, `dispatchSync()`) are located at the `dispatch()` call, not at the throw.
- Closures run by `Concurrency::run()` with the `process` driver are not supported: their exceptions are located in `artisan`.

## Requirements

- PHP 8.3+
- Laravel 12.41+ or 13
- MySQL/MariaDB, PostgreSQL or SQLite (SQL Server is not supported)

## Installation

You can install the package via composer:

```bash
composer require keepsuit/laravel-spyglass
```

Publish and run the migration, which creates all the Spyglass tables:

```bash
php artisan vendor:publish --tag="spyglass-migrations"
php artisan migrate
```

The tables are created on the connection set by `SPYGLASS_DB_CONNECTION` (the default connection otherwise). In a multi-tenant application, keep the migration with the central migrations (for example `database/migrations`, not a tenant migrations path): Spyglass stores every tenant's entries in one place, filterable by scope.

You can publish the config file with:

```bash
php artisan vendor:publish --tag="spyglass-config"
```

The dashboard is served at `/spyglass` (`SPYGLASS_PATH`, optionally on `SPYGLASS_DOMAIN`), behind the `viewSpyglass` gate. Its assets ship prebuilt in `dist/`, built by CI on `main`: there is nothing to publish.

### Logs

Spyglass declares a `spyglass` log channel. To record logs, add it to the application's stack. With the default `config/logging.php`, set it in `.env`:

```dotenv
LOG_STACK=single,spyglass
```

Or list it in the stack channels of a customised `config/logging.php`:

```php
'stack' => ['driver' => 'stack', 'channels' => ['stderr', 'spyglass']],
```

### Modules

Modules are listed in `spyglass.modules`, keyed by class, in dashboard order. Each one can be turned off with its `SPYGLASS_<MODULE>_ENABLED` variable (for example `SPYGLASS_LOGS_ENABLED=false`). A module missing from a published config file is disabled: after an upgrade that adds a module, copy its entry from the package config to enable it.

### Storage and cache

Entries are written through a copy of the storage connection registered as `spyglass`, so they stay outside the application's transactions: a rollback or a deadlock on either side does not affect the other. On SQLite, which allows a single writer, the connection itself is used. The connection and cache store names are resolved once at boot, so runtime switches of the defaults (for example per-tenant databases) do not affect them.

The cache throttles repeated exceptions and log messages across processes and holds queue sizes for a few seconds. Set `SPYGLASS_CACHE_STORE=array` to keep it per process. `SPYGLASS_CACHE_STORE=null` does not disable it: `env()` reads it as `null`, which selects the application's default store.

### Pruning

`spyglass:prune` deletes entries older than each module's retention and is scheduled daily. To run it on your own schedule, set `schedule_prune` to `false` and schedule the command yourself:

```php
Schedule::command('spyglass:prune')->dailyAt('03:00');
```

## Testing

```bash
composer test
```

## Dashboard development

The dashboard is a React application in `resources/js`. CI builds it into `dist/` and commits the build on `main`; pull requests never carry `dist/`, which is ignored. Build it locally to try a change in an application:

```bash
npm install
npm run build   # or `npm run dev` to rebuild on change
npm test
```

To look at it with demo data for every module, serve the Testbench workbench and open http://localhost:8000/spyglass:

```bash
composer serve   # builds a SQLite database, seeds it and starts the server
composer build   # reseeds without restarting: the demo data is relative to now
```

The workbench lives in `workbench/`: `WorkbenchServiceProvider` opens the gate and resolves a tenant scope, `DatabaseSeeder` writes the demo data. With `npm run dev` running in another terminal, reloading the page picks up frontend changes.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Fabio Capucci](https://github.com/cappuc)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
