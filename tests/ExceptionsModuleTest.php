<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Keepsuit\Spyglass\Facades\Spyglass as SpyglassFacade;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionQueries;
use Keepsuit\Spyglass\Modules\Exceptions\ExceptionsModule;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Support\ExceptionFingerprint;
use Keepsuit\Spyglass\Tests\Fixtures\ConcurrentException;
use Keepsuit\Spyglass\Tests\Fixtures\ForwardingExceptionHandler;
use Keepsuit\Spyglass\Tests\Fixtures\QueueJob;
use Keepsuit\Spyglass\Tests\Fixtures\TestJob;
use Keepsuit\Spyglass\Tests\Fixtures\WithExceptionsModule;
use NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler as CollisionHandler;
use Symfony\Component\ErrorHandler\Error\FatalError;

uses(WithExceptionsModule::class);

class ExceptionsModuleTestException extends RuntimeException {}

class ThrowingBeforeRouting
{
    public function handle(): never
    {
        throw new RuntimeException('Before routing');
    }
}

/**
 * Every exception built here has the same fingerprint.
 */
function sameSiteException(string $message = 'Boom'): RuntimeException
{
    return new RuntimeException($message);
}

function exceptionGroup(): array
{
    return toSeconds(exceptionGroupsTable()->sole());
}

function exceptionOccurrences(): array
{
    return exceptionOccurrencesTable()->orderBy('id')->get()->map(toSeconds(...))->all();
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

    expect(exceptionGroupsTable()->count())->toBe(0)
        ->and(exceptionOccurrencesTable()->count())->toBe(0);
});

it('registers on a handler bound after boot, including decorators', function (Closure $handler) {
    app()->instance(ExceptionHandler::class, $handler());

    reportAndFlush(sameSiteException());

    expect(exceptionGroup()['count'])->toBe(1);
})->with([
    'handler' => fn () => new Handler(app()),
    // Collision wraps the application handler in console processes outside tests.
    'Collision decorator' => fn () => new CollisionHandler(app(), new Handler(app())),
    'decorator forwarding through __call' => fn () => new ForwardingExceptionHandler(new Handler(app())),
]);

it('lets the application fake exceptions after turning off exception handling', function (bool $except) {
    $except ? $this->handleExceptions([ExceptionsModuleTestException::class]) : $this->withoutExceptionHandling();
    Exceptions::fake();

    report(sameSiteException());

    Exceptions::assertReported(RuntimeException::class);
})->with(['withoutExceptionHandling' => false, 'handleExceptions' => true]);

it('still records through a fake that forwards to the application handler', function () {
    Exceptions::fake(ExceptionsModuleTestException::class);

    reportAndFlush(sameSiteException());

    expect(exceptionGroup()['count'])->toBe(1);
});

it('counts the same exception instance once', function () {
    $e = sameSiteException();
    report($e);
    report($e);
    SpyglassFacade::flush();

    expect(exceptionGroup()['count'])->toBe(1);
});

it('adds up counts written concurrently by separate processes', function () {
    $db = SpyglassFacade::connection();
    $this->travelTo('2100-01-01 00:00:00');

    // The open transaction holds the group row, so the other process writes while this one is still writing.
    $db->beginTransaction();
    report(ConcurrentException::make());
    report(ConcurrentException::make());
    SpyglassFacade::flush();

    $process = fixtureProcess('report');
    $process->start();

    $waiting = fn () => DB::scalar(DB::getDriverName() === 'pgsql'
        ? "select count(*) from pg_stat_activity where wait_event_type = 'Lock'"
        : "select count(*) from information_schema.innodb_trx where trx_state = 'LOCK WAIT'");

    for ($i = 0; $i < 100 && $process->isRunning() && $waiting() === 0; $i++) {
        usleep(100_000);
    }

    expect($waiting())->toBe(1);

    $db->commit();
    $process->wait();

    expect($process->getErrorOutput())->toBe('')
        ->and(exceptionGroup())->toMatchArray(['count' => 4, 'last_seen_at' => '2100-01-01 00:00:00'])
        ->and(exceptionOccurrences())->toHaveCount(2);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'SQLite allows a single writer');

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

it('reopens a group on an occurrence later in the same second as its resolution', function () {
    $fingerprint = ExceptionFingerprint::for(sameSiteException());

    $this->travelTo('2026-03-01 10:00:00.100');
    reportAndFlush(sameSiteException());
    app(ExceptionQueries::class)->resolve($fingerprint);

    $this->travelTo('2026-03-01 10:00:00.400');
    reportAndFlush(sameSiteException());

    expect(app(ExceptionQueries::class)->groups(['status' => 'unresolved'])->items())->toHaveCount(1);
});

it('reopens a resolved group on an occurrence newer than the resolution, even when stored late', function () {
    $fingerprint = ExceptionFingerprint::for(sameSiteException());
    $queries = app(ExceptionQueries::class);

    $this->travelTo('2026-03-01 10:00:00');
    reportAndFlush(sameSiteException());

    $this->travelTo('2026-03-01 10:01:00');
    report(sameSiteException());

    $this->travelTo('2026-03-01 10:02:00');
    expect($queries->resolve($fingerprint))->toBeTrue();

    SpyglassFacade::flush();

    expect(toSeconds($queries->group($fingerprint)))->toMatchArray(['resolved_at' => '2026-03-01 10:02:00', 'count' => 2])
        ->and($queries->groups(['status' => 'resolved'])->items())->toHaveCount(1);

    $this->travelTo('2026-03-01 10:03:00');
    reportAndFlush(sameSiteException());

    expect($queries->group($fingerprint))->toMatchObject(['resolved_at' => null, 'count' => 3])
        ->and($queries->groups(['status' => 'unresolved'])->items())->toHaveCount(1);
});

it('stores at most one occurrence per minute and fingerprint, and counts all of them', function () {
    foreach (range(1, 3) as $i) {
        report(sameSiteException());
    }
    report(new ExceptionsModuleTestException);
    SpyglassFacade::flush();

    expect(exceptionOccurrences())->toHaveCount(2)
        ->and(exceptionGroupsTable()->orderBy('class')->pluck('count')->all())->toBe([1, 3]);

    $this->travel(61)->seconds();
    reportAndFlush(sameSiteException());

    expect(exceptionOccurrences())->toHaveCount(3);
});

it('throttles within the process when the cache is unavailable, trying it again a minute later', function () {
    $attempts = 0;
    Cache::extend('failing', function () use (&$attempts) {
        return Cache::repository(new class($attempts) extends ArrayStore
        {
            public function __construct(public int &$attempts) {}

            public function add($key, $value, $seconds)
            {
                $this->attempts++;

                throw new RuntimeException('Cache down');
            }
        });
    });
    config()->set('cache.stores.failing', ['driver' => 'failing']);
    app()->instance(Spyglass::class, new Spyglass(SpyglassFacade::connection()->getName(), 'failing'));
    SpyglassFacade::clearResolvedInstance(Spyglass::class);

    reportAndFlush(sameSiteException());
    reportAndFlush(sameSiteException());
    reportAndFlush(new ExceptionsModuleTestException);

    expect(exceptionOccurrences())->toHaveCount(2)
        ->and($attempts)->toBe(1)
        ->and($this->spyglassErrors)->toHaveCount(1)
        ->and($this->spyglassErrors[0]->message)->toContain('throttle');
    $this->spyglassErrors = [];

    $this->travel(61)->seconds();
    reportAndFlush(sameSiteException());

    expect(exceptionOccurrences())->toHaveCount(3)
        ->and($attempts)->toBe(2);
    $this->spyglassErrors = [];
});

it('throttles in the process before asking the cache', function () {
    reportAndFlush(sameSiteException());
    SpyglassFacade::cache()->flush();
    reportAndFlush(sameSiteException());

    expect(exceptionOccurrences())->toHaveCount(1);
});

it('throttles within the process with a cache store that keeps nothing', function () {
    config()->set('cache.stores.none', ['driver' => 'null']);
    app()->instance(Spyglass::class, new Spyglass(SpyglassFacade::connection()->getName(), 'none'));
    SpyglassFacade::clearResolvedInstance(Spyglass::class);

    reportAndFlush(sameSiteException());
    reportAndFlush(sameSiteException());
    reportAndFlush(new ExceptionsModuleTestException);

    expect(exceptionOccurrences())->toHaveCount(2);
});

it('throttles each scope on its own', function () {
    config()->set('spyglass.context', ['tenant_id']);
    SpyglassFacade::resolveScopeUsing(fn (array $context) => $context['tenant_id'] ?? null);

    foreach (['acme', 'acme', 'other'] as $tenant) {
        Context::add('tenant_id', $tenant);
        report(sameSiteException());
    }
    SpyglassFacade::flush();

    expect(array_column(exceptionOccurrences(), 'scope'))->toBe(['acme', 'other'])
        ->and(exceptionGroup()['count'])->toBe(3);
});

it('does not record exceptions reported by application code it runs while recording', function () {
    DB::table('users')->insert(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
    app('session.store')->put(Auth::guard()->getName(), 7);
    // Runs on the query that resolves the user for the occurrence. Once: Laravel's handler resolves the user too.
    $reported = false;
    DB::listen(function () use (&$reported) {
        if (! $reported) {
            $reported = true;
            report(new LogicException('Listener'));
        }
    });

    reportAndFlush(sameSiteException());

    expect(exceptionGroupsTable()->pluck('class')->all())->toBe([RuntimeException::class]);
});

it('stores neither the path nor the query string of a request not routed yet', function () {
    // A global middleware runs before routing.
    app(HttpKernel::class)->pushMiddleware(ThrowingBeforeRouting::class);
    Route::get('/reset/{token}', fn () => 'ok');

    $this->get('/reset/private-token?email=a@b.c')->assertServerError();
    SpyglassFacade::flush();

    expect(exceptionOccurrences()[0])->toMatchArray(['source_type' => 'request', 'source' => 'GET (unrouted)', 'route' => null]);
});

it('stores the request with query string values masked, except allowlisted keys', function () {
    config()->set(moduleKey('exceptions').'.query_allowlist', ['page']);
    Route::get('/orders/{order}', fn () => throw sameSiteException())->name('orders.show');

    $this->get('/orders/12?page=2&token=secret&filter[email]=a@b.c&flag')->assertServerError();
    SpyglassFacade::flush();

    expect(exceptionOccurrences()[0])->toMatchArray([
        'source_type' => 'request',
        'source' => 'GET /orders/{order}?filter%5Bemail%5D=***&flag=***&page=2&token=***',
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

it('stores the id of a user the guard has yet to resolve', function () {
    DB::table('users')->insert(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
    app('session.store')->put(Auth::guard()->getName(), 7);

    reportAndFlush(sameSiteException());

    expect(exceptionOccurrences()[0]['user_id'])->toBe('7');
});

it('stores query errors without the driver message and the bindings', function () {
    DB::table('users')->insert(['name' => 'Ada', 'email' => 'secret@example.com', 'password' => 'x']);

    try {
        DB::table('users')->insert(['name' => 'Ada', 'email' => 'secret@example.com', 'password' => 'x']);
    } catch (QueryException $e) {
        reportAndFlush($e);
    }

    expect($e->getMessage())->toContain('secret@example.com')
        ->and(exceptionGroup()['message'])->toBe("SQLSTATE[{$e->getCode()}]: {$e->getSql()}")
        ->and(exceptionOccurrences()[0]['message'])->toBe("SQLSTATE[{$e->getCode()}]: {$e->getSql()}")
        ->and(json_encode([exceptionGroup(), exceptionOccurrences()]))->not->toContain('secret');
});

it('does not let a job outlive its attempt in the source of later exceptions', function () {
    config()->set('spyglass.context', ['tenant_id']);
    SpyglassFacade::resolveScopeUsing(fn (array $context) => $context['tenant_id'] ?? null);
    Context::add('tenant_id', 'acme');
    TestJob::dispatch('a', fail: true);
    Context::forget('tenant_id');

    Route::get('/work', function () {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
        Context::add('tenant_id', 'other');
        report(new LogicException('After the job'));

        return 'ok';
    });

    $this->get('/work')->assertOk();

    expect(exceptionOccurrencesTable()->orderBy('id')->get()->map(fn ($row) => Arr::only((array) $row, ['source_type', 'source', 'scope']))->all())->toBe([
        ['source_type' => 'job', 'source' => TestJob::class, 'scope' => 'acme'],
        ['source_type' => 'request', 'source' => 'GET /work', 'scope' => 'other'],
    ]);
});

it('stores the job and its scope, captured before tenancy reverts the context', function () {
    config()->set('spyglass.context', ['tenant_id']);
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

it('stores the current scope of a job that reports after switching tenant', function () {
    config()->set('spyglass.context', ['tenant_id']);
    SpyglassFacade::resolveScopeUsing(fn (array $context) => isset($context['tenant_id']) ? "tenant:{$context['tenant_id']}" : null);

    Context::add('tenant_id', 'acme');
    dispatch(function () {
        Context::add('tenant_id', 'other');
        report(new RuntimeException('Switched'));
    });
    Context::forget('tenant_id');

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    SpyglassFacade::flush();

    expect(exceptionOccurrences()[0])->toMatchArray(['source_type' => 'job', 'scope' => 'tenant:other']);
});

it('locates an exception thrown in a queued closure at its line, one group per closure', function () {
    app()->setBasePath(dirname(__DIR__));

    dispatch(function () {
        throw new LogicException('First');
    });
    $first = __LINE__ - 2;
    dispatch(function () {
        throw new LogicException('Second');
    });
    $second = __LINE__ - 2;

    foreach ([1, 2] as $attempt) {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    }
    SpyglassFacade::flush();

    expect(exceptionGroupsTable()->orderBy('line')->get()->map(fn (object $group) => ['file' => $group->file, 'line' => (int) $group->line])->all())->toBe([
        ['file' => 'tests/ExceptionsModuleTest.php', 'line' => $first],
        ['file' => 'tests/ExceptionsModuleTest.php', 'line' => $second],
    ]);
});

it('names the job after its class, not a display name that can embed ids', function () {
    QueueJob::dispatch('throw', 'Order #12');
    $uuid = json_decode(DB::table('jobs')->value('payload'), true)['uuid'];

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    SpyglassFacade::flush();

    expect(exceptionOccurrences()[0])->toMatchArray(['source_type' => 'job', 'source' => QueueJob::class])
        ->and(exceptionOccurrences()[0]['source_id'])->toBe($uuid);
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

    expect(mb_strlen(exceptionGroup()['message']))->toBe(4096)
        ->and(mb_check_encoding(exceptionGroup()['message'], 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($occurrence['message']))->toBe(4096)
        ->and(json_decode($occurrence['trace'], true))->toHaveCount(50);
});

it('strips NUL bytes, which anonymous class names contain', function () {
    reportAndFlush(new class("Bo\0om") extends RuntimeException {});

    expect(exceptionGroup()['class'])->toStartWith('RuntimeException@anonymous')->not->toContain("\0")
        ->and(strlen(exceptionGroup()['class']))->toBeLessThanOrEqual(255)
        ->and(exceptionGroup()['message'])->toBe('Boom')
        ->and(exceptionOccurrences()[0]['message'])->toBe('Boom');
});

it('stores the occurrence of a fatal error when the process ends', function () {
    $db = runToProcessEnd('fatal');

    expect(exceptionGroupsTable($db)->first()->class)->toBe(FatalError::class)
        ->and(exceptionOccurrencesTable($db)->count())->toBe(1);
});

it('does not record exceptions reported while Spyglass is reporting its own failures', function () {
    SpyglassFacade::ignore(fn () => report(sameSiteException('Internal')));
    SpyglassFacade::flush();

    expect(exceptionGroupsTable()->count())->toBe(0);

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
        ->and(exceptionGroupsTable()->pluck('class')->all())->toBe([RuntimeException::class])
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

it('prunes groups resolved before their retention, unless they occurred again', function () {
    $queries = app(ExceptionQueries::class);
    $other = fn () => new ExceptionsModuleTestException;

    $this->travelTo(now()->subDays(31));
    reportAndFlush(sameSiteException());
    reportAndFlush($other());
    $queries->resolve(ExceptionFingerprint::for(sameSiteException()));
    $queries->resolve(ExceptionFingerprint::for($other()));

    $this->travelBack();
    reportAndFlush($other());
    config()->set(moduleKey('exceptions').'.retention.occurrences', 60);

    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(exceptionGroupsTable()->pluck('class')->all())->toBe([ExceptionsModuleTestException::class]);
});

it('stores the root cause of a wrapped exception, without query bindings', function () {
    $query = new QueryException('testing', 'select * from users where email = ?', ['secret@example.com'], new PDOException('boom'));

    reportAndFlush(new RuntimeException('Sync failed', previous: new LogicException('Retry failed', previous: $query)));
    reportAndFlush(new ExceptionsModuleTestException);

    // Its previous is the driver error, whose message can hold the data.
    reportAndFlush(new QueryException('testing', 'insert into users (email) values (?)', ['a@b.c'], new PDOException("Duplicate entry 'a@b.c'")));

    expect(array_map(fn ($occurrence) => [$occurrence['cause_class'], $occurrence['cause_message']], exceptionOccurrences()))->toBe([
        [QueryException::class, 'SQLSTATE[0]: select * from users where email = ?'],
        [null, null],
        [null, null],
    ]);
});

it('keeps the driver message of a PDO error out, whether wrapped, wrapping or copied', function () {
    $driver = new PDOException("SQLSTATE[23000]: Duplicate entry 'mario@example.com' for key 'email'", 23000);
    $query = new QueryException('testing', 'insert into users (email) values (?)', ['mario@example.com'], $driver);

    reportAndFlush(new RuntimeException('Import failed', previous: $driver));
    reportAndFlush($driver);
    reportAndFlush(new LogicException('Rendering failed: '.$query->getMessage(), previous: $query));

    foreach (exceptionOccurrences() as $occurrence) {
        expect($occurrence['message'].' '.$occurrence['cause_message'])->not->toContain('mario@example.com');
    }

    expect(array_column(exceptionOccurrences(), 'cause_message'))->toContain('SQLSTATE[23000]');
});

it('removes the sources of the resolved groups it prunes', function () {
    config()->set(moduleKey('exceptions').'.retention.resolved_groups', 1);
    $fingerprint = ExceptionFingerprint::for(sameSiteException());

    reportAndFlush(sameSiteException());
    app(ExceptionQueries::class)->resolve($fingerprint);

    $this->travel(2)->days();
    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(app(ExceptionQueries::class)->sources($fingerprint))->toBe([]);
});

it('counts every source of a group, beyond the throttled occurrences', function () {
    Route::get('/checkout', fn () => throw sameSiteException());
    Route::get('/orders/{order}', fn () => throw sameSiteException());

    foreach (['/checkout?page=1', '/checkout?page=2', '/checkout', '/orders/12'] as $path) {
        $this->get($path)->assertServerError();
    }
    SpyglassFacade::flush();

    expect(exceptionOccurrences())->toHaveCount(1)
        ->and(collect(app(ExceptionQueries::class)->sources(ExceptionFingerprint::for(sameSiteException())))->map(fn ($source) => [$source->source_type, $source->source, (int) $source->count])->all())
        ->toBe([['request', 'GET /checkout', 3], ['request', 'GET /orders/{order}', 1]]);
});

it('prunes old occurrences and groups last seen before their retention', function () {
    $this->travelTo(now()->subDays(31));
    reportAndFlush(sameSiteException());
    reportAndFlush(new ExceptionsModuleTestException);

    $this->travelBack();
    reportAndFlush(new ExceptionsModuleTestException);

    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(exceptionGroupsTable()->pluck('class')->all())->toBe([ExceptionsModuleTestException::class])
        ->and(exceptionOccurrences())->toHaveCount(1)
        ->and(sourcesTable()->where('kind', 'exception')->count())->toBe(1);
});
