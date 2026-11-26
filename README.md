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

## Known limitations

- `Bus::batch()` on the `database` queue driver does not fire `JobQueued`, so the `queued` counter undercounts batched jobs there.
- On SQLite, a worker timeout inside an open database transaction loses the counters of that attempt.
- A worker started directly on the target of a queue forward (`Queue::forward('a', 'b')`, worker on `b`) counts the attempts under `b`, while `queued` is counted under `a`.

## Requirements

- PHP 8.3+
- Laravel 12.25+ or 13
- MySQL/MariaDB, PostgreSQL or SQLite (SQL Server is not supported)

## Installation

You can install the package via composer:

```bash
composer require keepsuit/laravel-spyglass
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-spyglass-config"
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
