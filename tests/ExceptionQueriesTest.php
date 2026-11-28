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
    exceptionQueries()->group(str_repeat('a', 40), 'tenant:acme');
    exceptionQueries()->occurrences(str_repeat('a', 40), perPage: -1, scope: 'tenant:acme');

    $log = collect(Spyglass::connection()->getQueryLog())->pluck('query');

    // The scope is joined as an aggregate over its occurrences, so it never cuts the groups it considers. The other
    // queries are the latest message of each group, the group and its totals in the scope.
    expect($log->every(fn (string $sql) => str_contains($sql, 'limit') || str_contains($sql, 'count(*)')))->toBeTrue()
        ->and($log[0])->toContain('join')->toContain('limit 101')
        ->and($log->filter(fn (string $sql) => str_contains($sql, 'count(*)') && ! str_contains($sql, 'join')))->toHaveCount(1);
});

describe('in a scope', function () {
    beforeEach(function () {
        $this->fingerprint = sha1('shared');
        // The same exception in two tenants: the group holds the totals across them.
        seedExceptionGroup($this->fingerprint, ['message' => 'In beta', 'count' => 9, 'first_seen_at' => '2026-03-01 09:00:00', 'last_seen_at' => '2026-03-01 13:00:00']);
        $occurrence = fn (string $scope, string $at, string $message) => DB::table(ExceptionsModule::OCCURRENCES)->insert([
            'kind' => 'exception', 'signature' => $this->fingerprint, 'recorded_at' => $at, 'message' => $message, 'body' => '[]', 'scope' => $scope,
        ]);
        $occurrence('alpha', '2026-03-01 09:00:00', 'Older in alpha');
        $occurrence('alpha', '2026-03-01 10:00:00', 'In alpha');
        $occurrence('beta', '2026-03-01 12:00:00', 'In beta');
        $occurrence('beta', '2026-03-01 13:00:00', 'In beta');
    });

    it('lists the totals and the latest message of the scope', function () {
        $group = collect(exceptionQueries()->groups(['scope' => 'alpha'])->items())->firstWhere('fingerprint', $this->fingerprint);
        $all = collect(exceptionQueries()->groups()->items())->firstWhere('fingerprint', $this->fingerprint);

        expect(toSeconds($group))->toMatchArray(['count' => 2, 'message' => 'In alpha', 'first_seen_at' => '2026-03-01 09:00:00', 'last_seen_at' => '2026-03-01 10:00:00', 'resolved_at' => null])
            ->and(array_keys((array) $group))->toBe(array_keys((array) $all))
            ->and(toSeconds($all))->toMatchArray(['count' => 9, 'message' => 'In beta', 'last_seen_at' => '2026-03-01 13:00:00']);
    });

    it('shows the group and its occurrences in the scope', function () {
        expect(toSeconds(exceptionQueries()->group($this->fingerprint, 'alpha')))->toMatchArray(['count' => 2, 'message' => 'In alpha', 'last_seen_at' => '2026-03-01 10:00:00'])
            ->and(collect(exceptionQueries()->occurrences($this->fingerprint, scope: 'alpha')->items())->pluck('message')->all())->toBe(['In alpha', 'Older in alpha'])
            ->and(exceptionQueries()->group($this->fingerprint)->count)->toBe(9)
            ->and(exceptionQueries()->occurrences($this->fingerprint)->items())->toHaveCount(4)
            ->and(toSeconds(exceptionQueries()->group($this->fingerprint, 'gamma')))->toMatchArray(['count' => 0, 'message' => null, 'last_seen_at' => null]);
    });

    it('sorts by the totals of the scope', function () {
        // Fewer in total, more in alpha, and seen there later.
        seedExceptionGroup(sha1('other'), ['count' => 1, 'last_seen_at' => '2026-03-01 11:00:00'], ['alpha', 'alpha', 'alpha']);
        DB::table(ExceptionsModule::OCCURRENCES)->where('signature', sha1('other'))->update(['recorded_at' => '2026-03-01 11:00:00']);
        $order = fn (?array $sort) => collect(exceptionQueries()->groups(['scope' => 'alpha'], sort: $sort)->items())->pluck('fingerprint')->all();

        expect($order(['count', true]))->toBe([sha1('other'), $this->fingerprint])
            ->and($order(['count', false]))->toBe([$this->fingerprint, sha1('other')])
            ->and($order(null))->toBe([sha1('other'), $this->fingerprint])
            ->and($order(['last_seen_at', false]))->toBe([$this->fingerprint, sha1('other')]);
    });

    it('keeps the status of the group, reopened by another scope', function () {
        DB::table(ExceptionsModule::GROUPS)->where('signature', $this->fingerprint)->update(['resolved_at' => '2026-03-01 11:00:00']);

        expect(exceptionQueries()->group($this->fingerprint, 'alpha')->resolved_at)->toBeNull()
            ->and(collect(exceptionQueries()->groups(['scope' => 'alpha', 'status' => 'unresolved'])->items())->pluck('fingerprint'))->toContain($this->fingerprint)
            ->and(collect(exceptionQueries()->groups(['scope' => 'alpha', 'status' => 'resolved'])->items())->pluck('fingerprint'))->not->toContain($this->fingerprint);
    });
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
