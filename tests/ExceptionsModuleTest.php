<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Auth\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Keepsuit\Spyglass\Facades\Spyglass as SpyglassFacade;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionQueries;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Support\ExceptionFingerprint;
use Keepsuit\Spyglass\Tests\Fixtures\TestJob;
use Keepsuit\Spyglass\Tests\Fixtures\WithExceptionsModule;

uses(WithExceptionsModule::class);

class ExceptionsModuleTestException extends RuntimeException {}

/**
 * Every exception built here has the same fingerprint.
 */
function sameSiteException(string $message = 'Boom'): RuntimeException
{
    return new RuntimeException($message);
}

function exceptionGroup(): array
{
    return (array) DB::table(ExceptionsModule::GROUPS)->sole();
}

function exceptionOccurrences(): array
{
    return DB::table(ExceptionsModule::OCCURRENCES)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

function reportAndFlush(Throwable $e): void
{
    report($e);
    SpyglassFacade::flush();
}

it('records a group and an occurrence through the exception handler', function () {
    $e = sameSiteException('Order 12 failed');
    $this->travelTo('2026-03-01 10:00:00');
    reportAndFlush($e);

    expect(exceptionGroup())->toMatchArray([
        'fingerprint' => ExceptionFingerprint::for($e),
        'class' => RuntimeException::class,
        'message' => 'Order 12 failed',
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'count' => 1,
        'first_seen_at' => '2026-03-01 10:00:00',
        'last_seen_at' => '2026-03-01 10:00:00',
        'resolved_at' => null,
    ]);

    $occurrence = exceptionOccurrences()[0];
    $trace = json_decode($occurrence['trace'], true);

    expect($occurrence)->toMatchArray([
        'fingerprint' => ExceptionFingerprint::for($e),
        'occurred_at' => '2026-03-01 10:00:00',
        'message' => 'Order 12 failed',
        'user_id' => null,
    ])
        ->and($trace[0])->toBe(['file' => __FILE__, 'line' => $e->getTrace()[0]['line'], 'function' => 'sameSiteException'])
        ->and($trace[1])->toHaveKeys(['file', 'line', 'function'])->not->toHaveKey('args');
});

it('keeps reporting to the log after recording', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message;
    });

    reportAndFlush(sameSiteException('Still logged'));

    expect($logged)->toContain('Still logged')
        ->and(exceptionGroup()['count'])->toBe(1);
});

it('respects dontReport', function () {
    app(ExceptionHandler::class)->dontReport(ExceptionsModuleTestException::class);

    reportAndFlush(new ExceptionsModuleTestException('Ignored'));

    expect(DB::table(ExceptionsModule::GROUPS)->count())->toBe(0)
        ->and(DB::table(ExceptionsModule::OCCURRENCES)->count())->toBe(0);
});

it('counts the same exception instance once', function () {
    $e = sameSiteException();
    report($e);
    report($e);
    SpyglassFacade::flush();

    expect(exceptionGroup()['count'])->toBe(1);
});

it('adds up counts buffered by separate processes', function () {
    $connection = SpyglassFacade::connection()->getName();
    $processes = [];

    foreach ([1, 2] as $i) {
        $processes[$i] = new Spyglass($connection, 'array');
        app()->instance(Spyglass::class, $processes[$i]);
        SpyglassFacade::clearResolvedInstance(Spyglass::class);

        report(sameSiteException());
        report(sameSiteException());
    }

    $processes[2]->flush();
    $processes[1]->flush();

    expect(exceptionGroup()['count'])->toBe(4);
});

it('keeps the first seen time and moves the last seen time', function () {
    $this->travelTo('2026-03-01 10:00:00');
    reportAndFlush(sameSiteException());

    $this->travelTo('2026-03-01 12:00:00');
    reportAndFlush(sameSiteException('Later message'));

    expect(exceptionGroup())->toMatchArray([
        'count' => 2,
        'message' => 'Later message',
        'first_seen_at' => '2026-03-01 10:00:00',
        'last_seen_at' => '2026-03-01 12:00:00',
    ]);
});

it('reopens a resolved group on a new occurrence', function () {
    $e = sameSiteException();
    reportAndFlush($e);

    expect(app(ExceptionQueries::class)->resolve(ExceptionFingerprint::for($e)))->toBeTrue()
        ->and(exceptionGroup()['resolved_at'])->not->toBeNull();

    reportAndFlush(sameSiteException());

    expect(exceptionGroup())->toMatchArray(['resolved_at' => null, 'count' => 2]);
});

it('stores at most one occurrence per minute and fingerprint, and counts all of them', function () {
    foreach (range(1, 3) as $i) {
        report(sameSiteException());
    }
    report(new ExceptionsModuleTestException);
    SpyglassFacade::flush();

    expect(exceptionOccurrences())->toHaveCount(2)
        ->and(DB::table(ExceptionsModule::GROUPS)->orderBy('class')->pluck('count')->all())->toBe([1, 3]);

    $this->travel(61)->seconds();
    reportAndFlush(sameSiteException());

    expect(exceptionOccurrences())->toHaveCount(3);
});

it('records occurrences when the cache is unavailable', function () {
    app()->instance(Spyglass::class, new Spyglass(SpyglassFacade::connection()->getName(), 'missing'));
    SpyglassFacade::clearResolvedInstance(Spyglass::class);

    reportAndFlush(sameSiteException());
    reportAndFlush(sameSiteException());

    expect(exceptionOccurrences())->toHaveCount(2)
        ->and($this->spyglassErrors)->toHaveCount(1)
        ->and($this->spyglassErrors[0]->message)->toContain('throttle');
    $this->spyglassErrors = [];
});

it('stores the request with query string values masked, except allowlisted keys', function () {
    config()->set('spyglass.modules.exceptions.query_allowlist', ['page']);
    Route::get('/orders/{order}', fn () => throw sameSiteException())->name('orders.show');

    $this->get('/orders/12?page=2&token=secret&filter[email]=a@b.c&flag')->assertServerError();
    SpyglassFacade::flush();

    expect(exceptionOccurrences()[0])->toMatchArray([
        'source_type' => 'request',
        'source' => 'GET /orders/12?filter%5Bemail%5D=***&flag=***&page=2&token=***',
        'route' => 'orders.show',
    ]);
});

it('never stores the request body, headers or cookies', function () {
    Route::post('/orders', fn () => throw sameSiteException());

    $this->withHeader('X-Api-Key', 'header-secret')
        ->withCookie('remember', 'cookie-secret')
        ->post('/orders', ['password' => 'body-secret'])
        ->assertServerError();
    SpyglassFacade::flush();

    $stored = json_encode([exceptionGroup(), exceptionOccurrences()]);

    expect($stored)->not->toContain('header-secret')
        ->not->toContain('cookie-secret')
        ->not->toContain('body-secret')
        ->and(exceptionOccurrences()[0]['source'])->toBe('POST /orders');
});

it('stores the id of a user the guard already holds', function () {
    $user = (new User)->forceFill(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
    $this->actingAs($user);

    reportAndFlush(sameSiteException());

    expect(exceptionOccurrences()[0]['user_id'])->toBe('7');
});

it('never queries the database to resolve the user', function () {
    DB::table('users')->insert(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
    app('session.store')->put(Auth::guard()->getName(), 7);
    DB::enableQueryLog();
    $queries = null;
    // Runs right after the Spyglass callback, before Laravel's handler resolves the user for its own log context.
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$queries) {
        $queries = DB::getQueryLog();
    });

    report(sameSiteException());

    expect($queries)->toBe([]);

    SpyglassFacade::flush();

    expect(exceptionOccurrences()[0]['user_id'])->toBeNull();
});

it('stores the job and its scope, captured before tenancy reverts the context', function () {
    config()->set('spyglass.context.keys', ['tenant_id']);
    SpyglassFacade::resolveScopeUsing(fn (array $context) => isset($context['tenant_id']) ? "tenant:{$context['tenant_id']}" : null);
    Event::listen(JobFailed::class, fn () => Context::forget('tenant_id'));

    Context::add('tenant_id', 'acme');
    TestJob::dispatch('a', fail: true);
    Context::forget('tenant_id');

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    SpyglassFacade::flush();

    expect(exceptionOccurrences()[0])->toMatchArray([
        'source_type' => 'job',
        'source' => TestJob::class,
        'route' => null,
        'scope' => 'tenant:acme',
    ])
        ->and(json_decode(exceptionOccurrences()[0]['context'], true))->toBe(['tenant_id' => 'acme']);
});

it('stores the console command name, without its arguments', function () {
    $argv = $_SERVER['argv'];
    $_SERVER['argv'] = ['artisan', 'orders:sync', '--token=secret'];

    try {
        reportAndFlush(sameSiteException());
    } finally {
        $_SERVER['argv'] = $argv;
    }

    expect(exceptionOccurrences()[0])->toMatchArray(['source_type' => 'command', 'source' => 'orders:sync']);
});

it('truncates messages and bounds the trace', function () {
    $deep = function (int $depth) use (&$deep) {
        return $depth === 0 ? sameSiteException(str_repeat('è', 5000)) : $deep($depth - 1);
    };

    reportAndFlush($deep(80));

    $occurrence = exceptionOccurrences()[0];

    expect(strlen(exceptionGroup()['message']))->toBe(4096)
        ->and(mb_check_encoding(exceptionGroup()['message'], 'UTF-8'))->toBeTrue()
        ->and(strlen($occurrence['message']))->toBe(4096)
        ->and(json_decode($occurrence['trace'], true))->toHaveCount(50);
});

it('does not record exceptions reported while Spyglass is reporting its own failures', function () {
    SpyglassFacade::ignore(fn () => report(sameSiteException('Internal')));
    SpyglassFacade::flush();

    expect(DB::table(ExceptionsModule::GROUPS)->count())->toBe(0);

    // The application's own exception from the same site is not throttled by the internal one.
    reportAndFlush(sameSiteException());

    expect(exceptionOccurrences())->toHaveCount(1);
});

it('does not record anything when storing exceptions fails', function () {
    $logged = 0;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged++;

        // An application listener that reports what it sees must not loop back into Spyglass.
        if (isset($event->context['exception']) && str_starts_with($event->message, 'Spyglass')) {
            report($event->context['exception']);
        }
    });
    SpyglassFacade::connection()->getSchemaBuilder()->drop(ExceptionsModule::OCCURRENCES);

    reportAndFlush(sameSiteException());
    SpyglassFacade::flush();

    expect($this->spyglassErrors)->toHaveCount(1)
        ->and(DB::table(ExceptionsModule::GROUPS)->pluck('class')->all())->toBe([RuntimeException::class])
        // The exception, the storage failure, and the failure reported by the listener: no loop.
        ->and($logged)->toBe(3);
    $this->spyglassErrors = [];
});

it('stores nothing for a request without exceptions', function () {
    Route::get('/ok', fn () => 'ok');
    SpyglassFacade::connection()->enableQueryLog();

    $this->get('/ok')->assertOk();
    SpyglassFacade::flush();

    expect(SpyglassFacade::connection()->getQueryLog())->toBe([]);
});

it('prunes old occurrences and groups last seen before their retention', function () {
    $this->travelTo(now()->subDays(31));
    reportAndFlush(sameSiteException());
    reportAndFlush(new ExceptionsModuleTestException);

    $this->travelBack();
    reportAndFlush(new ExceptionsModuleTestException);

    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(DB::table(ExceptionsModule::GROUPS)->pluck('class')->all())->toBe([ExceptionsModuleTestException::class])
        ->and(exceptionOccurrences())->toHaveCount(1);
});
