<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Keepsuit\Spyglass\Facades\Spyglass;

function entries(): array
{
    return DB::table('spyglass_test_entries')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

it('buffers inserts until flushed', function () {
    Spyglass::insert('spyglass_test_entries', ['name' => 'a']);
    Spyglass::insert('spyglass_test_entries', ['name' => 'b', 'created_at' => now()]);

    expect(entries())->toBeEmpty();

    Spyglass::flush();
    Spyglass::flush();

    expect(array_column(entries(), 'name'))->toBe(['a', 'b']);
});

it('merges upserts in memory and increments existing rows', function () {
    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'a'], values: ['label' => 'first'], increment: ['count' => 1], max: ['duration_max' => 5]);
    Spyglass::upsert('spyglass_test_counters', ['name' => 'a', 'bucket' => 'b1'], values: ['label' => 'last'], increment: ['count' => 2], max: ['duration_max' => 3]);
    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'b'], increment: ['count' => 1]);
    Spyglass::flush();

    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'a'], increment: ['count' => 4], max: ['duration_max' => 9]);
    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'b'], increment: ['count' => 1], max: ['duration_max' => 1]);
    Spyglass::flush();

    $rows = DB::table('spyglass_test_counters')->orderBy('name')->get();

    expect($rows)->toHaveCount(2)
        ->and((array) $rows[0])->toMatchArray(['name' => 'a', 'label' => 'last', 'count' => 7, 'duration_max' => 9])
        ->and((array) $rows[1])->toMatchArray(['name' => 'b', 'label' => null, 'count' => 2, 'duration_max' => 1]);
});

it('increments with the MySQL upsert alias syntax', function () {
    $connection = config('database.default');
    config()->set("database.connections.{$connection}.use_upsert_alias", true);
    DB::purge($connection);

    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'a'], increment: ['count' => 1], max: ['duration_max' => 5]);
    Spyglass::flush();
    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'a'], increment: ['count' => 2], max: ['duration_max' => 3]);
    Spyglass::flush();

    $row = (array) DB::table('spyglass_test_counters')->first();

    // The purged connection is outside the test transaction.
    DB::table('spyglass_test_counters')->delete();

    expect($row)->toMatchArray(['count' => 3, 'duration_max' => 5]);
})->skip(fn () => ! in_array(DB::getDriverName(), ['mysql', 'mariadb']), 'MySQL only');

it('captures scope and context when the entry is created', function () {
    config()->set('spyglass.context.keys', ['tenant_id', 'secret']);
    Spyglass::resolveScopeUsing(fn (array $context) => isset($context['tenant_id']) ? 'tenant:'.$context['tenant_id'] : null);

    Context::add('tenant_id', 'acme');
    Context::add('other', 'ignored');
    Context::addHidden('secret', 'hidden');

    Spyglass::insert('spyglass_test_entries', ['name' => 'a']);
    Context::flush();
    Spyglass::insert('spyglass_test_entries', ['name' => 'b']);
    Spyglass::flush();

    [$a, $b] = entries();

    expect($a['scope'])->toBe('tenant:acme')
        ->and(json_decode($a['context'], true))->toBe(['tenant_id' => 'acme'])
        ->and($b['scope'])->toBeNull()
        ->and($b['context'])->toBeNull();
});

it('keeps a scope captured earlier by the caller', function () {
    Spyglass::resolveScopeUsing(fn () => 'now');

    Spyglass::insert('spyglass_test_entries', ['name' => 'a', 'scope' => 'earlier', 'context' => null]);
    Spyglass::flush();

    expect(entries()[0]['scope'])->toBe('earlier');
});

it('resolves scope labels', function () {
    expect(Spyglass::scopeLabels(['tenant:a', 'tenant:b']))->toBe(['tenant:a' => 'tenant:a', 'tenant:b' => 'tenant:b']);

    Spyglass::resolveScopeLabelsUsing(fn (array $scopes) => ['tenant:a' => 'Tenant A']);

    expect(Spyglass::scopeLabels(['tenant:a', 'tenant:b']))->toBe(['tenant:a' => 'Tenant A', 'tenant:b' => 'tenant:b']);
});

it('writes to the connection pinned at boot when the default connection changes', function () {
    $pinned = config('database.default');

    config()->set('database.default', 'tenant');
    DB::purge('tenant');

    Spyglass::insert('spyglass_test_entries', ['name' => 'a']);
    Spyglass::flush();

    expect(DB::connection($pinned)->table('spyglass_test_entries')->count())->toBe(1)
        ->and(Spyglass::connection()->getName())->toBe($pinned);

    config()->set('database.default', $pinned);
});

it('uses the cache store pinned at boot when the default store changes', function () {
    config()->set('cache.default', 'file');

    expect(Spyglass::cache()->getStore())->toBeInstanceOf(ArrayStore::class);
});

it('reports a storage failure once and never records its own logs', function () {
    Log::listen(fn () => Spyglass::insert('spyglass_test_entries', ['name' => 'from-log']));

    Spyglass::insert('spyglass_missing_a', ['name' => 'a']);
    Spyglass::insert('spyglass_missing_b', ['name' => 'b']);
    Spyglass::flush();

    expect($this->spyglassErrors)->toHaveCount(1);

    // The buffer was emptied and the log listener could not refill it.
    Spyglass::flush();

    expect($this->spyglassErrors)->toHaveCount(1);
    $this->spyglassErrors = [];
});

it('keeps an open application transaction usable when storage fails', function () {
    DB::transaction(function () {
        DB::table('spyglass_test_entries')->insert(['name' => 'before']);

        Spyglass::insert('spyglass_missing', ['name' => 'a']);
        Spyglass::flush();

        DB::table('spyglass_test_entries')->insert(['name' => 'after']);
    });

    expect(array_column(entries(), 'name'))->toBe(['before', 'after'])
        ->and($this->spyglassErrors)->toHaveCount(1);
    $this->spyglassErrors = [];
});
