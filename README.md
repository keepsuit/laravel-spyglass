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
- **Health**: database and cache connectivity, queue sizes, debug mode, runtime versions, deployed commit.

Later: scheduler and queue heartbeats, alerts, on-demand request tracing, and an API for custom modules.

## Stored data

Spyglass never stores job payloads, query bindings or the driver message of query errors, and masks query string values unless allowed by `spyglass.modules.exceptions.query_allowlist`. It does store exception messages, the authenticated user id of an occurrence, log messages with their context and the configured Context keys: these can hold personal data, so treat the Spyglass tables like application logs when it comes to retention and access.

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
- `Bus::batch()` on the `database` queue driver does not fire `JobQueued`, so the `queued` counter undercounts batched jobs there.
- On SQLite, a worker timeout inside an open database transaction loses the counters of that attempt.
- A worker started directly on the target of a queue forward (`Queue::forward('a', 'b')`, worker on `b`) counts the attempts under `b`, while `queued` is counted under `a`.
- Exceptions with their own `report()` method, or stopped by an application `reportable` callback registered earlier (returning `false` or calling `stop()`), are not recorded.
- A manual `Log::error(..., ['exception' => $e])` after `report($e)` is skipped as already recorded.
- With the schedule module disabled, exceptions and logs of scheduled tasks have the `schedule:run` command as their source, not the task.
- The `source_id` of entries from a successful run of a sub-minute task points to no run, since those runs are not stored.
- With a `database` cache store, occurrences and log entries are throttled per process only, so several processes can store the same one in a minute: a cache query inside an open transaction could abort it on PostgreSQL.

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

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Fabio Capucci](https://github.com/cappuc)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
