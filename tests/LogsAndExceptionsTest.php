<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;
use Keepsuit\Spyglass\Modules\Logs\LogsModule;
use Keepsuit\Spyglass\Tests\Fixtures\WithExceptionsAndLogsModules;

uses(WithExceptionsAndLogsModules::class);

function logMessages(): array
{
    return DB::table(LogsModule::ENTRIES)->orderBy('id')->pluck('message')->all();
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
        ->and(DB::table(ExceptionsModule::GROUPS)->value('count'))->toBe(2);
});

it('keeps manual logs of exceptions that were never reported', function () {
    Log::error('Manual', ['exception' => new RuntimeException('Caught')]);
    Spyglass::flush();

    expect(logMessages())->toBe(['Manual'])
        ->and(DB::table(ExceptionsModule::GROUPS)->count())->toBe(0);
});

it('records neither an exception nor a log entry for its own storage failures', function () {
    Spyglass::connection()->getSchemaBuilder()->drop(ExceptionsModule::OCCURRENCES);

    report(sameSiteLoggedException());
    Spyglass::flush();
    Spyglass::flush();

    expect($this->spyglassErrors)->toHaveCount(1)
        ->and(logMessages())->toBe([])
        ->and(DB::table(ExceptionsModule::GROUPS)->pluck('class')->all())->toBe([RuntimeException::class]);
    $this->spyglassErrors = [];
});
