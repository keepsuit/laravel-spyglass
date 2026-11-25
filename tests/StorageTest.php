<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Keepsuit\Spyglass\Facades\Spyglass;

$sharesApplicationConnection = fn () => DB::getDriverName() === 'sqlite';

it('keeps entries when the application transaction rolls back', function () {
    try {
        DB::transaction(function () {
            Spyglass::insert('spyglass_test_entries', ['name' => 'a']);
            Spyglass::flush();

            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('spyglass_test_entries')->pluck('name')->all())->toBe(['a']);
})->skip($sharesApplicationConnection, 'SQLite storage shares the application connection');

it('opens the storage connection only when there is something to store', function () {
    Route::get('/idle', fn () => 'ok');
    Route::get('/record', function () {
        Spyglass::insert('spyglass_test_entries', ['name' => 'a']);

        return 'ok';
    });

    $this->get('/idle')->assertOk();

    expect(DB::getConnections())->not->toHaveKey('spyglass');

    $this->get('/record')->assertOk();

    expect(DB::getConnections())->toHaveKey('spyglass');
})->skip($sharesApplicationConnection, 'SQLite storage shares the application connection');

it('keeps the application working when the storage server is unreachable', function () {
    $name = Spyglass::connection()->getName();
    $config = config("database.connections.{$name}");
    $pdo = Spyglass::connection()->getPdo();

    // A closed local port refuses at once; SQLite fails on the missing file.
    config()->set("database.connections.{$name}", ['host' => '127.0.0.1', 'port' => 1, 'database' => '/nonexistent/spyglass.sqlite'] + $config);
    DB::purge($name);

    Route::get('/record', function () {
        Spyglass::insert('spyglass_test_entries', ['name' => 'a']);

        return 'ok';
    });

    $this->get('/record')->assertOk()->assertContent('ok');
    $this->get('/record')->assertOk()->assertContent('ok');

    expect($this->spyglassErrors)->toHaveCount(1)
        ->and($this->spyglassErrors[0]->context['exception'])->not->toBeInstanceOf(QueryException::class);
    $this->spyglassErrors = [];

    // Testbench rolls back the migrations afterwards; an in-memory SQLite database lives only as long as its PDO.
    config()->set("database.connections.{$name}", $config);
    DB::purge($name);
    DB::connection($name)->setPdo($pdo);
});
