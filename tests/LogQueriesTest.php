<?php

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Logs\LogQueries;
use Keepsuit\Spyglass\Modules\Logs\LogsModule;
use Keepsuit\Spyglass\Tests\Fixtures\WithLogsModule;

uses(WithLogsModule::class);

function seedLogEntry(string $message, string $loggedAt, array $values = []): void
{
    DB::table(LogsModule::ENTRIES)->insert([
        'level' => 'error',
        'channel' => 'production',
        'message' => $message,
        'logged_at' => $loggedAt,
        ...$values,
    ]);
}

function logQueries(): LogQueries
{
    return app(LogQueries::class);
}

beforeEach(function () {
    seedLogEntry('a', '2026-03-01 10:00:00', ['level' => 'warning', 'scope' => 'tenant:acme']);
    seedLogEntry('b', '2026-03-01 11:00:00', ['channel' => 'worker']);
    seedLogEntry('c', '2026-03-01 12:00:00', ['scope' => 'tenant:acme']);
});

it('lists entries newest first, with filters', function (array $filters, array $expected) {
    expect(collect(logQueries()->entries($filters)->items())->pluck('message')->all())->toBe($expected);
})->with([
    'all' => [[], ['c', 'b', 'a']],
    'level' => [['level' => 'error'], ['c', 'b']],
    'channel' => [['channel' => 'worker'], ['b']],
    'scope' => [['scope' => 'tenant:acme'], ['c', 'a']],
    'from' => [fn () => ['from' => Date::parse('2026-03-01 11:00:00', 'UTC')], ['c', 'b']],
    'to in another timezone' => [fn () => ['to' => Date::parse('2026-03-01 12:00:00', 'Europe/Rome')], ['b', 'a']],
    'combined' => [fn () => ['scope' => 'tenant:acme', 'level' => 'error', 'from' => Date::parse('2026-03-01 09:00:00', 'UTC')], ['c']],
]);

it('paginates and bounds every query', function () {
    Spyglass::connection()->enableQueryLog();

    $first = logQueries()->entries(perPage: 2);
    $second = logQueries()->entries(perPage: 2, page: 2);
    logQueries()->entries(perPage: 1000);

    expect($first->items())->toHaveCount(2)
        ->and($first->hasMorePages())->toBeTrue()
        ->and(collect($second->items())->pluck('message')->all())->toBe(['a'])
        ->and(collect(Spyglass::connection()->getQueryLog())->pluck('query')->map(fn (string $sql) => str($sql)->match('/limit \d+/')->value())->all())
        ->toBe(['limit 3', 'limit 3', 'limit 101']);
});
