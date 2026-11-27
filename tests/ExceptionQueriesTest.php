<?php

use Illuminate\Support\Facades\DB;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionQueries;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;
use Keepsuit\Spyglass\Tests\Fixtures\WithExceptionsModule;

uses(WithExceptionsModule::class);

function exceptionQueries(): ExceptionQueries
{
    return app(ExceptionQueries::class);
}

beforeEach(function () {
    seedExceptionGroup(str_repeat('a', 40), ['last_seen_at' => '2026-03-01 12:00:00'], ['tenant:acme', 'tenant:acme']);
    seedExceptionGroup(str_repeat('b', 40), ['class' => LogicException::class, 'resolved_at' => '2026-03-02 10:00:00'], ['tenant:other']);
    seedExceptionGroup(str_repeat('c', 40), ['last_seen_at' => '2026-03-01 11:00:00'], ['tenant:acme', 'tenant:other']);
});

it('lists groups last seen first, with filters', function (array $filters, array $expected) {
    expect(collect(exceptionQueries()->groups($filters)->items())->pluck('fingerprint')->map(fn ($f) => $f[0])->all())->toBe($expected);
})->with([
    'all' => [[], ['a', 'c', 'b']],
    'unresolved' => [['status' => 'unresolved'], ['a', 'c']],
    'resolved' => [['status' => 'resolved'], ['b']],
    'class' => [['class' => LogicException::class], ['b']],
    'scope' => [['scope' => 'tenant:other'], ['c', 'b']],
    'combined' => [['scope' => 'tenant:other', 'status' => 'unresolved'], ['c']],
    // Falsy, still a filter.
    'scope "0"' => [['scope' => '0'], []],
    'class "0"' => [['class' => '0'], []],
]);

it('paginates groups', function () {
    $first = exceptionQueries()->groups(perPage: 2);
    $second = exceptionQueries()->groups(perPage: 2, page: 2);

    expect($first->hasMorePages())->toBeTrue()
        ->and($first->items())->toHaveCount(2)
        ->and($second->items())->toHaveCount(1)
        ->and($second->hasMorePages())->toBeFalse();
});

it('shows a group with its occurrences, newest first', function () {
    DB::table(ExceptionsModule::OCCURRENCES)->where('signature', str_repeat('a', 40))->limit(1)->update(['recorded_at' => '2026-03-01 12:00:00', 'message' => 'Newest']);

    expect(exceptionQueries()->group(str_repeat('a', 40))->class)->toBe(RuntimeException::class)
        ->and(exceptionQueries()->group(str_repeat('z', 40)))->toBeNull()
        ->and(collect(exceptionQueries()->occurrences(str_repeat('a', 40))->items())->pluck('message')->all())->toBe(['Newest', 'Boom'])
        ->and(exceptionQueries()->occurrences(str_repeat('a', 40), perPage: 1)->items())->toHaveCount(1);
});

it('resolves and reopens a group', function () {
    $this->travelTo('2026-03-05 08:00:00');

    expect(exceptionQueries()->resolve(str_repeat('a', 40)))->toBeTrue()
        ->and(exceptionQueries()->resolve(str_repeat('a', 40)))->toBeFalse()
        ->and(substr(exceptionQueries()->group(str_repeat('a', 40))->resolved_at, 0, 19))->toBe('2026-03-05 08:00:00')
        ->and(exceptionQueries()->unresolve(str_repeat('a', 40)))->toBeTrue()
        ->and(exceptionQueries()->unresolve(str_repeat('a', 40)))->toBeFalse()
        ->and(exceptionQueries()->group(str_repeat('a', 40))->resolved_at)->toBeNull();
});

it('bounds every query', function () {
    Spyglass::connection()->enableQueryLog();

    exceptionQueries()->groups(['scope' => 'tenant:acme'], perPage: 1000);
    exceptionQueries()->group(str_repeat('a', 40));
    exceptionQueries()->occurrences(str_repeat('a', 40), perPage: -1);

    $log = Spyglass::connection()->getQueryLog();

    // The scope filter is a correlated subquery, so it never cuts the groups it considers.
    expect($log)->toHaveCount(3)
        ->and(collect($log)->pluck('query')->every(fn (string $sql) => str_contains($sql, 'limit')))->toBeTrue()
        ->and($log[0]['query'])->toContain('exists')->toContain('limit 101')
        ->and($log[1]['query'])->toContain('limit 1');
});

it('suggests classes and scopes, bounded and in order', function () {
    seedExceptionGroup(sha1('suggest-logic'), ['class' => LogicException::class], ['tenant:b', 'tenant:a']);
    seedExceptionGroup(sha1('suggest-domain'), ['class' => DomainException::class], ['tenant:a']);
    Spyglass::connection()->enableQueryLog();

    $classes = exceptionQueries()->classes(1000);
    $scopes = exceptionQueries()->scopes(1000);

    expect($classes)->toContain(DomainException::class, LogicException::class)->toBe(collect($classes)->unique()->sort()->values()->all())
        ->and($scopes)->toContain('tenant:a', 'tenant:b')->toBe(collect($scopes)->unique()->sort()->values()->all())
        ->and(collect(Spyglass::connection()->getQueryLog())->pluck('query')->every(fn (string $sql) => str_contains($sql, 'limit 100')))->toBeTrue();
});
