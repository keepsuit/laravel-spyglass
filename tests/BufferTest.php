<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
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
    $connection = Spyglass::connection()->getName();
    config()->set("database.connections.{$connection}.use_upsert_alias", true);
    DB::purge($connection);

    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'a'], increment: ['count' => 1], max: ['duration_max' => 5]);
    Spyglass::flush();
    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'a'], increment: ['count' => 2], max: ['duration_max' => 3]);
    Spyglass::flush();

    expect((array) DB::table('spyglass_test_counters')->first())->toMatchArray(['count' => 3, 'duration_max' => 5]);
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

it('stores entries without scope when the scope resolver throws', function () {
    config()->set('spyglass.context.keys', ['tenant_id']);
    Context::add('tenant_id', 'acme');
    Spyglass::resolveScopeUsing(fn () => throw new RuntimeException('Resolver failed'));

    Route::get('/record', function () {
        Spyglass::insert('spyglass_test_entries', ['name' => 'a']);
        Spyglass::insert('spyglass_test_entries', ['name' => 'b']);

        return 'ok';
    });

    $this->get('/record')->assertOk()->assertContent('ok');

    expect(entries())->sequence(
        fn ($entry) => $entry->toMatchArray(['name' => 'a', 'scope' => null, 'context' => null]),
        fn ($entry) => $entry->toMatchArray(['name' => 'b', 'scope' => null, 'context' => null]),
    )->and($this->spyglassErrors)->toHaveCount(1);
    $this->spyglassErrors = [];
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
        ->and(Spyglass::connection()->getName())->not->toBe('tenant');

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

    expect(entries())->toBeEmpty();
    $this->spyglassErrors = [];
});

it('reports storage failures at most once a minute', function () {
    $fail = function () {
        Spyglass::insert('spyglass_missing', ['name' => 'a']);
        Spyglass::flush();
    };

    $fail();
    $fail();

    expect($this->spyglassErrors)->toHaveCount(1);

    $this->travel(61)->seconds();
    $fail();

    expect($this->spyglassErrors)->toHaveCount(2);
    $this->spyglassErrors = [];
});

it('stores the other tables when one of them fails', function () {
    Spyglass::insert('spyglass_missing', ['name' => 'a']);
    Spyglass::insert('spyglass_test_entries', ['name' => 'b']);
    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'c'], increment: ['count' => 1]);
    Spyglass::flush();

    expect(array_column(entries(), 'name'))->toBe(['b'])
        ->and(DB::table('spyglass_test_counters')->count())->toBe(1)
        ->and($this->spyglassErrors)->toHaveCount(1);
    $this->spyglassErrors = [];
});

it('limits each statement to 999 bound parameters', function () {
    foreach (range(1, 1000) as $i) {
        Spyglass::insert('spyglass_test_entries', ['name' => "entry {$i}"]);
    }

    Spyglass::connection()->enableQueryLog();
    Spyglass::flush();

    // name, scope and context: 333 rows per statement.
    expect(Spyglass::connection()->getQueryLog())->toHaveCount(4)
        ->and(DB::table('spyglass_test_entries')->count())->toBe(1000);
});

it('flushes when the buffer exceeds 1000 entries', function () {
    foreach (range(1, 1000) as $i) {
        Spyglass::insert('spyglass_test_entries', ['name' => "entry {$i}"]);
    }

    expect(DB::table('spyglass_test_entries')->count())->toBe(0);

    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'a'], increment: ['count' => 1]);

    expect(DB::table('spyglass_test_entries')->count())->toBe(1000)
        ->and(DB::table('spyglass_test_counters')->count())->toBe(1);
});

it('merges upsert keys that differ only in their scalar type', function () {
    Spyglass::upsert('spyglass_test_counters', ['bucket' => 1, 'name' => 'a'], increment: ['count' => 1]);
    Spyglass::upsert('spyglass_test_counters', ['bucket' => '1', 'name' => 'a'], increment: ['count' => 1]);

    Spyglass::connection()->enableQueryLog();
    Spyglass::flush();

    expect(Spyglass::connection()->getQueryLog()[0]['bindings'])->toHaveCount(3)
        ->and((array) DB::table('spyglass_test_counters')->first())->toMatchArray(['bucket' => '1', 'count' => 2]);
});

it('writes upserts in key order', function () {
    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'b'], increment: ['count' => 1]);
    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'a'], increment: ['count' => 1]);

    Spyglass::connection()->enableQueryLog();
    Spyglass::flush();

    expect(Spyglass::connection()->getQueryLog()[0]['bindings'])->toBe(['b1', 1, 'a', 'b1', 1, 'b']);
});

it('increments and maximizes columns left NULL by an earlier upsert', function () {
    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'a'], increment: ['count' => 1]);
    Spyglass::flush();

    Spyglass::upsert('spyglass_test_counters', ['bucket' => 'b1', 'name' => 'a'], increment: ['total' => 3], max: ['peak' => 5]);
    Spyglass::flush();

    expect((array) DB::table('spyglass_test_counters')->first())->toMatchArray(['count' => 1, 'total' => 3, 'peak' => 5]);
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
