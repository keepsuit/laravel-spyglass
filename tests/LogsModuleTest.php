<?php

use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Keepsuit\Spyglass\Facades\Spyglass as SpyglassFacade;
use Keepsuit\Spyglass\Modules\Logs\LogQueries;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Tests\Fixtures\WithLogsModule;
use Monolog\Handler\NoopHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Input\ArgvInput;

uses(WithLogsModule::class);

function logEntries(): array
{
    // To the second: drivers differ in how they return the milliseconds.
    return logEntriesTable()->orderBy('id')->get()->map(fn ($row) => ['logged_at' => substr($row->logged_at, 0, 19)] + (array) $row)->all();
}

it('stores records at or above the configured level', function () {
    config()->set('spyglass.context.keys', ['tenant_id']);
    SpyglassFacade::resolveScopeUsing(fn (array $context) => isset($context['tenant_id']) ? "tenant:{$context['tenant_id']}" : null);
    Context::add('tenant_id', 'acme');
    $this->travelTo('2026-03-01 10:00:00');

    Log::info('Not stored');
    Log::warning('Disk almost full', ['disk' => 'local', 'free' => 12]);
    Log::critical('Down');
    SpyglassFacade::flush();

    $entries = logEntries();

    expect(array_column($entries, 'message'))->toBe(['Disk almost full', 'Down'])
        ->and($entries[0])->toMatchArray([
            'level' => 'warning',
            'log_context' => '{"disk":"local","free":12}',
            'logged_at' => '2026-03-01 10:00:00',
            'scope' => 'tenant:acme',
        ])
        ->and($entries[1]['level'])->toBe('critical')
        ->and($entries[1]['log_context'])->toBeNull();
});

it('uses the configured level', function () {
    config()->set('spyglass.modules.logs.level', 'error');

    Log::warning('Not stored');
    Log::error('Stored');
    SpyglassFacade::flush();

    expect(array_column(logEntries(), 'message'))->toBe(['Stored']);
});

it('does nothing for records below the level', function () {
    SpyglassFacade::connection()->enableQueryLog();

    Log::info('Not stored');
    SpyglassFacade::flush();

    expect(SpyglassFacade::connection()->getQueryLog())->toBe([])
        // ArrayStore::all() does not exist on the lowest supported Laravel.
        ->and((fn () => $this->storage)->call(SpyglassFacade::cache()->getStore()))->toBe([]);
});

it('keeps the log of reported exceptions when the exceptions module is off', function () {
    report(new RuntimeException('Reported'));
    Log::error('Manual', ['exception' => new LogicException('Attached')]);
    SpyglassFacade::flush();

    $entries = logEntries();

    expect(array_column($entries, 'message'))->toBe(['Reported', 'Manual'])
        ->and($entries[1]['log_context'])->toContain('LogicException: Attached at '.__FILE__);
});

it('skips records Spyglass writes about its own failures, which still reach the log', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message;
    });

    SpyglassFacade::ignore(fn () => Log::error('Same message'));
    SpyglassFacade::flush();

    expect(logEntries())->toBe([])
        ->and($logged)->toBe(['Same message']);

    // The application's own record is not throttled by the internal one.
    Log::error('Same message');
    SpyglassFacade::flush();

    expect(logEntries())->toHaveCount(1);
});

it('stores the request and the job a record comes from', function () {
    Route::get('/orders/{order}', function () {
        Log::warning('Gateway slow');
    });

    $this->get('/orders/12?token=secret')->assertOk();
    dispatch(fn () => Log::warning('Gateway slow'));
    $uuid = json_decode(DB::table('jobs')->value('payload'), true)['uuid'];
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    SpyglassFacade::flush();

    expect(array_map(fn ($entry) => [$entry['source_type'], $entry['source'], $entry['source_id']], logEntries()))->toBe([
        ['request', 'GET /orders/{order}?token=***', null],
        ['job', 'Closure (LogsModuleTest.php:'.(__LINE__ - 7).')', $uuid],
    ]);
});

it('throttles each source on its own', function () {
    Route::get('/checkout', fn () => Log::warning('Gateway slow'));
    Route::get('/orders', fn () => Log::warning('Gateway slow'));

    $this->get('/checkout?page=1');
    $this->get('/checkout?page=2');
    $this->get('/orders');
    SpyglassFacade::flush();

    expect(array_column(logEntries(), 'source'))->toBe(['GET /checkout?page=***', 'GET /orders']);
});

it('stores at most one entry per minute for the same level and message', function () {
    foreach (range(1, 3) as $i) {
        Log::error('Payment failed', ['attempt' => $i]);
    }
    Log::warning('Payment failed');
    Log::error('Payment refused');
    SpyglassFacade::flush();

    expect(array_map(fn ($entry) => "{$entry['level']} {$entry['message']}", logEntries()))
        ->toBe(['error Payment failed', 'warning Payment failed', 'error Payment refused']);

    $this->travel(61)->seconds();
    Log::error('Payment failed');
    SpyglassFacade::flush();

    expect(logEntries())->toHaveCount(4);
});

it('throttles within the process when the cache is unavailable', function () {
    app()->instance(Spyglass::class, new Spyglass(SpyglassFacade::connection()->getName(), 'missing'));
    SpyglassFacade::clearResolvedInstance(Spyglass::class);

    Log::error('First');
    Log::error('First');
    Log::error('Second');
    SpyglassFacade::flush();

    expect(array_column(logEntries(), 'message'))->toBe(['First', 'Second'])
        ->and($this->spyglassErrors)->toHaveCount(1)
        ->and($this->spyglassErrors[0]->message)->toContain('throttle');
    $this->spyglassErrors = [];
});

it('throttles within the process with a cache store that keeps nothing', function () {
    config()->set('cache.stores.none', ['driver' => 'null']);
    app()->instance(Spyglass::class, new Spyglass(SpyglassFacade::connection()->getName(), 'none'));
    SpyglassFacade::clearResolvedInstance(Spyglass::class);

    Log::error('First');
    Log::error('First');
    Log::error('Second');
    SpyglassFacade::flush();

    expect(array_column(logEntries(), 'message'))->toBe(['First', 'Second']);
});

it('throttles in the process before asking the cache', function () {
    Log::error('First');
    SpyglassFacade::cache()->flush();
    Log::error('First');
    SpyglassFacade::flush();

    expect(logEntries())->toHaveCount(1);
});

it('throttles each scope on its own', function () {
    config()->set('spyglass.context.keys', ['tenant_id']);
    SpyglassFacade::resolveScopeUsing(fn (array $context) => $context['tenant_id'] ?? null);

    foreach (['acme', 'acme', 'other'] as $tenant) {
        Context::add('tenant_id', $tenant);
        Log::error('Payment failed');
    }
    SpyglassFacade::flush();

    expect(array_column(logEntries(), 'scope'))->toBe(['acme', 'other']);
});

it('does not store records logged by application code it runs while storing', function () {
    // Alone: other handlers serialize the context too, outside Spyglass.
    config()->set('logging.channels.stack.channels', ['spyglass']);
    Log::forgetChannel('stack');
    $context = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            Log::error('Logged while encoding');

            return 'encoded';
        }
    };

    Log::error('Outer', ['value' => $context]);
    SpyglassFacade::flush();

    expect(array_column(logEntries(), 'message'))->toBe(['Outer'])
        ->and(logEntries()[0]['log_context'])->toBe('{"value":"encoded"}');
});

it('stores at most 100 entries per lifecycle', function () {
    foreach (range(1, 101) as $i) {
        Log::error("Message {$i}");
    }
    SpyglassFacade::flush();

    expect(logEntries())->toHaveCount(100);

    Event::dispatch('Laravel\Octane\Events\RequestReceived');
    Log::error('Next request');
    SpyglassFacade::flush();

    expect(logEntries())->toHaveCount(101);
});

it('strips NUL bytes', function () {
    Log::error("Bo\0om", ['value' => "a\0b"]);
    SpyglassFacade::flush();

    expect(logEntries()[0])->toMatchArray(['message' => 'Boom', 'log_context' => '{"value":"a\\u0000b"}']);
});

it('truncates the message and the record context', function () {
    Log::error(str_repeat('è', 5000), ['payload' => str_repeat('x', 10000)]);
    SpyglassFacade::flush();

    $entry = logEntries()[0];

    expect(mb_strlen($entry['message']))->toBe(4096)
        ->and(mb_check_encoding($entry['message'], 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($entry['log_context']))->toBe(4096);
});

it('lets records reach the channels after it', function () {
    config()->set('logging.channels.stack.channels', ['spyglass', 'test']);
    Log::forgetChannel('stack');

    Log::error('Shared');
    SpyglassFacade::flush();

    expect(logEntries())->toHaveCount(1)
        ->and(Log::channel('test')->getLogger()->getHandlers()[0]->hasErrorThatContains('Shared'))->toBeTrue();
});

it('keeps the spyglass channel of a stack silent when Spyglass or the logs module is disabled', function (array $config) {
    $app = $this->bootApplication($config);

    $app['log']->channel('stack')->error('Still logged');
    $handler = $app['log']->channel('spyglass')->getLogger()->getHandlers()[0];

    // An unknown driver would add Laravel's emergency file handler, at debug level, in its place.
    expect(array_map(get_class(...), $app['log']->channel('stack')->getLogger()->getHandlers()))->toBe([TestHandler::class, NoopHandler::class])
        // Wherever it sits in the stack, it lets the record reach the channels after it.
        ->and($handler->handle(new LogRecord(new DateTimeImmutable, 'stack', Level::Error, 'Still logged')))->toBeFalse()
        ->and($app['log']->channel('test')->getLogger()->getHandlers()[0]->hasErrorThatContains('Still logged'))->toBeTrue();
})->with([
    'Spyglass disabled' => [['spyglass.enabled' => false]],
    'logs module disabled' => [['spyglass.modules.logs.enabled' => false]],
]);

it('counts every source of a message, beyond the throttled entries', function () {
    Route::get('/checkout', function () {
        Log::warning('Gateway slow');
        Log::warning('Gateway slow');
    });
    Log::warning('Gateway slow');

    $this->get('/checkout?page=1');
    $this->get('/checkout?page=2');
    SpyglassFacade::flush();

    expect(logEntries())->toHaveCount(2)
        ->and(collect(app(LogQueries::class)->sources('warning', 'Gateway slow'))->map(fn ($source) => [$source->source_type, $source->source, (int) $source->count])->all())
        ->toBe([['request', 'GET /checkout', 4], ['command', (string) (new ArgvInput)->getFirstArgument(), 1]]);
});

it('keeps routes with optional parameters apart', function () {
    Route::get('{locale?}/orders', fn () => Log::warning('Gateway slow'));
    Route::get('{locale?}/users', fn () => Log::warning('Gateway slow'));

    $this->get('/it/orders');
    $this->get('/it/users');
    SpyglassFacade::flush();

    expect(array_column(logEntries(), 'source'))->toBe(['GET /{locale?}/orders', 'GET /{locale?}/users'])
        ->and(collect(app(LogQueries::class)->sources('warning', 'Gateway slow'))->pluck('source')->sort()->values()->all())
        ->toBe(['GET /{locale?}/orders', 'GET /{locale?}/users']);
});

it('keeps long routes with the same beginning apart', function () {
    $prefix = str_repeat('segment/', 30);
    Route::get($prefix.'orders', fn () => Log::warning('Gateway slow'));
    Route::get($prefix.'users', fn () => Log::warning('Gateway slow'));

    $this->get('/'.$prefix.'orders');
    $this->get('/'.$prefix.'users');
    SpyglassFacade::flush();

    expect(app(LogQueries::class)->sources('warning', 'Gateway slow'))->toHaveCount(2);
});

it('keeps counting messages already seen beyond the entry cap', function () {
    Log::warning('Message 0');

    foreach (range(1, 120) as $i) {
        Log::warning("Message {$i}");
    }

    Log::warning('Message 0');
    Log::warning('Message 120');
    SpyglassFacade::flush();

    expect((int) app(LogQueries::class)->sources('warning', 'Message 0')[0]->count)->toBe(2)
        ->and(app(LogQueries::class)->sources('warning', 'Message 120'))->toBe([])
        ->and(logEntries())->toHaveCount(100);
});

it('prunes entries older than the retention', function () {
    $this->travelTo(now()->subDays(8));
    Log::error('Old');
    SpyglassFacade::flush();

    $this->travelBack();
    Log::error('New');
    SpyglassFacade::flush();

    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(array_column(logEntries(), 'message'))->toBe(['New'])
        ->and(sourcesTable()->where('kind', 'log')->count())->toBe(1);
});

it('restarts the entry cap every minute in processes without lifecycles', function () {
    $this->travelTo(now()->startOfMinute());

    foreach (range(1, 101) as $i) {
        Log::error("Message {$i}");
    }

    $this->travel(1)->minute();
    Log::error('Next minute');
    SpyglassFacade::flush();

    expect(logEntries())->toHaveCount(101);
});

it('stores query errors without the bindings when the exceptions module is off', function () {
    $exception = new QueryException('testing', 'select * from users where email = ?', ['secret@example.com'], new PDOException('boom'));

    Log::error($exception->getMessage(), ['exception' => $exception]);
    SpyglassFacade::flush();

    expect(logEntries()[0]['message'])->not->toContain('secret@example.com')
        ->and(logEntries()[0]['log_context'])->not->toContain('secret@example.com');
});

it('keeps the bindings of a query error out of a message that embeds it', function () {
    $exception = new QueryException('testing', 'select * from users where email = ?', ['secret@example.com'], new PDOException('boom'));

    Log::error('Database error: '.$exception->getMessage(), ['exception' => $exception]);
    SpyglassFacade::flush();

    expect(logEntries()[0]['message'])->toStartWith('Database error: SQLSTATE')
        ->and(logEntries()[0]['message'])->not->toContain('secret@example.com');
});
