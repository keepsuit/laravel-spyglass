<?php

use Illuminate\Support\Facades\Log;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;
use Keepsuit\Spyglass\Modules\Logs\LogQueries;
use Keepsuit\Spyglass\Tests\Fixtures\WithExceptionsAndLogsModules;

uses(WithExceptionsAndLogsModules::class);

function logMessages(): array
{
    return logEntriesTable()->orderBy('id')->pluck('message')->all();
}

function sameSiteLoggedException(): RuntimeException
{
    return new RuntimeException('Reported');
}

it('skips the log of exceptions recorded by the exceptions module', function () {
    report(sameSiteLoggedException());
    // Throttled occurrences are recorded too: the group counts them.
    report(sameSiteLoggedException());
    Spyglass::flush();

    expect(logMessages())->toBe([])
        ->and(exceptionGroupsTable()->first()->count)->toBe(2);
});

it('keeps manual logs of exceptions that were never reported', function () {
    Log::error('Manual', ['exception' => new RuntimeException('Caught')]);
    Spyglass::flush();

    expect(logMessages())->toBe(['Manual'])
        ->and(exceptionGroupsTable()->count())->toBe(0);
});

it('records neither an exception nor a log entry for its own storage failures', function () {
    Spyglass::connection()->getSchemaBuilder()->drop(ExceptionsModule::GROUPS);

    report(sameSiteLoggedException());
    Spyglass::flush();
    Spyglass::flush();

    expect($this->spyglassErrors)->toHaveCount(1)
        ->and(logMessages())->toBe([])
        ->and(exceptionOccurrencesTable()->pluck('message')->all())->toBe(['Reported']);
    $this->spyglassErrors = [];
});

it('prunes each kind of a shared table by its own retention', function () {
    $this->travelTo(now()->subDays(10));
    Log::error('Old log');
    report(new LogicException('Old exception'));
    Spyglass::flush();
    $this->travelBack();

    $this->artisan('spyglass:prune')->assertSuccessful();

    // Logs are kept 7 days, exceptions 30.
    expect(logMessages())->toBe([])
        ->and(sourcesTable()->where('kind', 'log')->count())->toBe(0)
        ->and(exceptionOccurrencesTable()->pluck('message')->all())->toBe(['Old exception'])
        ->and(exceptionGroupsTable()->count())->toBe(1)
        ->and(sourcesTable()->where('kind', 'exception')->count())->toBe(1);
});

it('lists only log entries among the shared entries', function () {
    report(new LogicException('Not a log'));
    Log::error('A log');
    Spyglass::flush();

    expect(collect(app(LogQueries::class)->entries()->items())->pluck('message')->all())->toBe(['A log']);
});
