<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Keepsuit\Spyglass\Facades\Spyglass as SpyglassFacade;
use Keepsuit\Spyglass\Modules\Logs\LogsModule;
use Keepsuit\Spyglass\Spyglass;
use Keepsuit\Spyglass\Tests\Fixtures\WithLogsModule;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;

uses(WithLogsModule::class);

function logEntries(): array
{
    return DB::table(LogsModule::ENTRIES)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
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
            'channel' => 'testing',
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
        ->and(SpyglassFacade::cache()->get('spyglass:log:'.sha1('200|Not stored')))->toBeNull();
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

it('stores entries when the cache is unavailable', function () {
    app()->instance(Spyglass::class, new Spyglass(SpyglassFacade::connection()->getName(), 'missing'));
    SpyglassFacade::clearResolvedInstance(Spyglass::class);

    Log::error('First');
    Log::error('First');
    SpyglassFacade::flush();

    expect(logEntries())->toHaveCount(2)
        ->and($this->spyglassErrors)->toHaveCount(1)
        ->and($this->spyglassErrors[0]->message)->toContain('throttle');
    $this->spyglassErrors = [];
});

it('truncates the message and the record context', function () {
    Log::error(str_repeat('è', 5000), ['payload' => str_repeat('x', 10000)]);
    SpyglassFacade::flush();

    $entry = logEntries()[0];

    expect(strlen($entry['message']))->toBe(4096)
        ->and(mb_check_encoding($entry['message'], 'UTF-8'))->toBeTrue()
        ->and(strlen($entry['log_context']))->toBe(4096);
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

    // An unknown driver would add Laravel's emergency file handler, at debug level, in its place.
    expect(array_map(get_class(...), $app['log']->channel('stack')->getLogger()->getHandlers()))->toBe([TestHandler::class, NullHandler::class])
        ->and($app['log']->channel('test')->getLogger()->getHandlers()[0]->hasErrorThatContains('Still logged'))->toBeTrue();
})->with([
    'Spyglass disabled' => [['spyglass.enabled' => false]],
    'logs module disabled' => [['spyglass.modules.logs.enabled' => false]],
]);

it('prunes entries older than the retention', function () {
    $this->travelTo(now()->subDays(8));
    Log::error('Old');
    SpyglassFacade::flush();

    $this->travelBack();
    Log::error('New');
    SpyglassFacade::flush();

    $this->artisan('spyglass:prune')->assertSuccessful();

    expect(array_column(logEntries(), 'message'))->toBe(['New']);
});
