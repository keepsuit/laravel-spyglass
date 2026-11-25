<?php

use Illuminate\Support\Facades\DB;
use Keepsuit\Spyglass\Facades\Spyglass;

beforeEach(function () {
    DB::table('spyglass_test_entries')->insert([
        ['name' => 'old', 'created_at' => now()->subDays(8)],
        ['name' => 'new', 'created_at' => now()->subDays(6)],
    ]);
});

it('prunes entries older than the module retention', function () {
    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(DB::table('spyglass_test_entries')->pluck('name')->all())->toBe(['new']);
});

it('prunes in batches of 1000 rows', function () {
    foreach (array_chunk(array_fill(0, 1000, ['name' => 'old', 'created_at' => now()->subDays(8)]), 300) as $rows) {
        DB::table('spyglass_test_entries')->insert($rows);
    }

    Spyglass::connection()->enableQueryLog();

    $this->artisan('spyglass:prune')->expectsOutputToContain('1001 deleted')->assertSuccessful();

    expect(Spyglass::connection()->getQueryLog())->toHaveCount(2)
        ->and(DB::table('spyglass_test_entries')->pluck('name')->all())->toBe(['new']);
});

it('does not prune disabled modules', function () {
    config()->set('spyglass.modules.test.enabled', false);

    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(DB::table('spyglass_test_entries')->count())->toBe(2);
});

it('schedules the prune command daily', function () {
    expect(pruneEvents())->toHaveCount(1)
        ->and(pruneEvents()[0]->expression)->toBe('0 0 * * *');
});

it('can disable the prune schedule', function () {
    expect(pruneEvents($this->bootApplication(['spyglass.prune.schedule' => false])))->toBeEmpty();
});
