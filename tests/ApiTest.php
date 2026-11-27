<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Modules\Health\HealthModule;
use Keepsuit\Spyglass\Modules\Logs\LogsModule;
use Keepsuit\Spyglass\Modules\Queue\QueueModule;
use Keepsuit\Spyglass\Modules\Schedule\ScheduleModule;
use Keepsuit\Spyglass\Support\Sources;
use Keepsuit\Spyglass\Tests\Fixtures\QueueJob;
use Keepsuit\Spyglass\Tests\Fixtures\WithAllModules;

uses(WithAllModules::class);

beforeEach(function () {
    Gate::define('viewSpyglass', fn ($user = null) => true);
    // Read at request time, so modules added after boot are listed without registering their listeners.
    Keepsuit\Spyglass\Spyglass::$modules += ['queue' => QueueModule::class, 'health' => HealthModule::class];

    config([
        'queue.failed.driver' => 'database-uuids',
        'queue.failed.database' => config('database.default'),
    ]);
});

/**
 * @return list<Route>
 */
function apiRoutes(): array
{
    return array_values(array_filter(
        app('router')->getRoutes()->getRoutes(),
        fn (Route $route) => str_starts_with((string) $route->getName(), 'spyglass.api.'),
    ));
}

/**
 * @param  array<string, string>  $parameters
 * @return array{string, string}
 */
function apiRequest(Route $route, array $parameters = []): array
{
    $uri = preg_replace_callback('/\{(\w+)\}/', fn (array $match) => $parameters[$match[1]] ?? '1', $route->uri());

    return [in_array('POST', $route->methods(), true) ? 'postJson' : 'getJson', '/'.$uri];
}

function apiWidget(array $page, string $key): array
{
    return apiWidgets($page)->firstWhere('key', $key) ?? throw new RuntimeException("No widget [{$key}].");
}

/**
 * Every widget of a page, those of groups included.
 *
 * @return Collection<int, array<string, mixed>>
 */
function apiWidgets(array $page): Collection
{
    return collect($page['widgets'])->flatMap(fn (array $widget) => [$widget, ...($widget['widgets'] ?? [])]);
}

function apiItems(array $page, string $key): array
{
    return collect(apiWidget($page, $key)['items'])->pluck('value', 'key')->all();
}

function apiFailJob(): string
{
    QueueJob::dispatch('throw');
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);

    return app('queue.failer')->ids()[0];
}

function apiTask(string $label): void
{
    DB::table(ScheduleModule::TASKS)->insert([
        'key' => sha1($label), 'label' => $label, 'expression' => '* * * * *', 'timezone' => 'UTC',
        'first_seen_at' => '2025-01-01 00:00:00', 'last_seen_at' => '2026-01-01 12:00:00',
    ]);
}

function apiLogEntry(string $level, string $message, string $body = '{}', string $at = '2026-03-01 12:00:00'): int
{
    return DB::table(Sources::ENTRIES)->insertGetId([
        'kind' => 'log', 'signature' => LogsModule::signature($level, $message), 'level' => $level, 'recorded_at' => $at,
        'message' => $message, 'body' => $body, 'source_type' => 'command', 'source' => 'backup', 'context' => '{"tenant":"a"}',
    ]);
}

it('denies every endpoint without authorization', function () {
    Spyglass::auth(fn () => false);

    expect(apiRoutes())->not->toBeEmpty();

    foreach (apiRoutes() as $route) {
        [$method, $uri] = apiRequest($route);

        $this->{$method}($uri)->assertForbidden();
    }
});

it('answers 404 on every endpoint of a disabled module, leaving its data untouched', function (string $module) {
    apiTask('inspire');
    $uuid = apiFailJob();
    seedExceptionGroup(str_repeat('a', 40));
    $parameters = ['task' => sha1('inspire'), 'uuid' => $uuid, 'fingerprint' => str_repeat('a', 40), 'id' => (string) apiLogEntry('error', 'Disk full')];

    config(["spyglass.modules.{$module}.enabled" => false]);

    $routes = array_filter(apiRoutes(), fn (Route $route) => explode('.', (string) $route->getName())[2] === $module);

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        [$method, $uri] = apiRequest($route, $parameters);

        $this->{$method}($uri)->assertNotFound();
    }

    expect(app('queue.failer')->find($uuid))->not->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table(Sources::GROUPS)->value('resolved_at'))->toBeNull()
        ->and($this->getJson('/spyglass/api')->json('modules.*.key'))->not->toContain($module)->toContain('test');
})->with(['schedule', 'queue', 'exceptions', 'logs', 'health']);

it('checks the CSRF token of every action, whatever the configured middleware', function () {
    $csrf = class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken';

    foreach (apiRoutes() as $route) {
        if (in_array('POST', $route->methods(), true)) {
            expect($route->gatherMiddleware())->toContain($csrf);
        }
    }

    // CSRF is not verified while running unit tests.
    $this->app['env'] = 'local';
    seedExceptionGroup(str_repeat('a', 40));

    $this->post('/spyglass/api/exceptions/'.str_repeat('a', 40).'/resolve')->assertStatus(419);
    $this->get('/spyglass/api/exceptions/'.str_repeat('a', 40).'/resolve')->assertMethodNotAllowed();
});

it('answers validation errors with JSON, whatever the request accepts', function () {
    $this->get('/spyglass/api/logs?level=loud')->assertUnprocessable()->assertJsonValidationErrors('level');
    $this->get('/spyglass/api/logs?page=99999999999')->assertUnprocessable()->assertJsonValidationErrors('page');
    $this->get('/spyglass/api/logs?page=0')->assertUnprocessable();
});

it('lists the enabled modules after the overview', function () {
    $modules = $this->getJson('/spyglass/api')->assertOk()->json('modules');

    expect($modules[0])->toBe(['key' => 'overview', 'label' => 'Overview'])
        ->and($modules)->toContain(['key' => 'queue', 'label' => 'Queue']);
});

it('describes the overview of the enabled modules', function () {
    Date::setTestNow('2026-03-01 12:30:00');
    apiTask('inspire');
    DB::table(ScheduleModule::RUNS)->insert([
        'id' => (string) Str::ulid(), 'task' => sha1('inspire'), 'started_at' => '2026-03-01 12:00:00', 'status' => 'failed', 'host' => 'web-1',
    ]);
    apiTask('archive');
    DB::table(ScheduleModule::TASKS)->where('label', 'archive')->update(['expression' => '0 0 1 1 *']);
    DB::table(ScheduleModule::RUNS)->insert([
        'id' => (string) Str::ulid(), 'task' => sha1('archive'), 'started_at' => '2026-01-01 00:00:00', 'status' => 'success', 'host' => 'web-1',
    ]);
    // The queue module joins after boot, without its listeners: the index row is written here.
    DB::table(QueueModule::FAILED_JOBS)->insert([
        'uuid' => $uuid = 'u-1', 'connection' => 'database', 'queue' => 'default', 'job' => 'A', 'attempts' => 1,
        'exception_class' => 'E', 'fingerprint' => str_repeat('a', 40), 'failed_at' => '2026-03-01 12:00:00',
    ]);
    seedExceptionGroup(str_repeat('a', 40), ['last_seen_at' => '2026-03-01 12:00:00']);
    seedExceptionGroup(str_repeat('b', 40), ['resolved_at' => '2026-03-02 00:00:00']);
    apiLogEntry('error', 'Disk full', at: '2026-03-01 12:00:00');
    apiLogEntry('error', 'Old', at: '2026-02-20 12:00:00');

    $page = $this->getJson('/spyglass/api/overview')->assertOk()->json();
    $metrics = collect($page['widgets'])->where('type', 'metric')->keyBy('key');

    expect($page['title'])->toBe('Overview')
        ->and($metrics['unresolved_exceptions'])->toMatchArray(['value' => 1, 'tone' => 'error', 'link' => 'exceptions?status=unresolved'])
        ->and($metrics['failed_jobs_total'])->toMatchArray(['value' => 1, 'tone' => 'error', 'link' => 'queue'])
        ->and($metrics['tasks_attention'])->toMatchArray(['value' => 1, 'tone' => 'warning'])
        ->and($metrics['log_entries']['value'])->toBe(1)
        ->and(collect($page['widgets'])->where('type', 'group')->pluck('key')->all())->toBe(['queue', 'exceptions', 'logs', 'schedule'])
        ->and(apiWidget($page, 'queue'))->toMatchArray(['more' => 'queue', 'width' => 'half'])
        ->and(array_column(apiWidget($page, 'queue')['widgets'], 'key'))->toBe(['throughput', 'failed_jobs'])
        ->and(apiWidget($page, 'unresolved')['rows'][0]['fingerprint'])->toBe(str_repeat('a', 40))
        ->and(apiWidget($page, 'failed_jobs'))->toMatchArray(['link' => 'queue/failed-jobs/{uuid}'])
        ->and(apiWidget($page, 'failed_jobs')['rows'][0]['uuid'])->toBe($uuid)
        ->and(apiWidget($page, 'tasks')['rows'][0])->toMatchArray(['label' => 'inspire', 'last_status' => 'failed'])
        ->and(array_column(apiWidget($page, 'latest_logs')['rows'], 'message'))->toBe(['Disk full'])
        ->and(apiWidget($page, 'logs')['widgets'][0])->toMatchArray(['key' => 'recorded', 'style' => 'bars'])
        // Every task, those needing attention first.
        ->and(array_column(apiWidget($page, 'tasks')['rows'], 'label'))->toBe(['inspire', 'archive'])
        ->and(apiWidget($page, 'throughput')['type'])->toBe('series');

    // Every link and "more" leads to a page of the API.
    foreach (apiWidgets($page)->flatMap(fn (array $widget) => array_filter([$widget['link'] ?? null, $widget['more'] ?? null])) as $link) {
        $path = preg_replace('/\{(\w+)\}/', '1', $link);
        expect(collect(apiRoutes())->contains(fn (Route $route) => $route->matches(Request::create('/spyglass/api/'.strtok($path, '?')), false)))->toBeTrue();
    }
});

it('leaves disabled modules out of the overview, without querying them', function () {
    config(['spyglass.modules.queue.enabled' => false, 'spyglass.modules.exceptions.enabled' => false]);
    Spyglass::connection()->enableQueryLog();

    $page = $this->getJson('/spyglass/api/overview')->assertOk()->json();

    expect(apiWidgets($page)->pluck('key')->all())->not->toContain('queue', 'failed_jobs', 'throughput', 'exceptions', 'unresolved', 'unresolved_exceptions')->toContain('schedule', 'tasks', 'logs')
        ->and(collect(Spyglass::connection()->getQueryLog())->pluck('query')->implode(' '))->not->toContain('spyglass_queue', 'spyglass_groups');
});

it('shows an empty overview when every module is disabled', function () {
    foreach (['schedule', 'queue', 'exceptions', 'logs', 'health'] as $module) {
        config(["spyglass.modules.{$module}.enabled" => false]);
    }

    expect($this->getJson('/spyglass/api/overview')->assertOk()->json('widgets'))->toBe([]);
});

it('describes the schedule', function () {
    Date::setTestNow('2026-01-01 12:00:30');
    apiTask('inspire');
    DB::table(ScheduleModule::RUNS)->insert([
        'id' => (string) Str::ulid(), 'task' => sha1('inspire'), 'started_at' => '2026-01-01 12:00:00.250',
        'finished_at' => '2026-01-01 12:00:01.250', 'duration_ms' => 1000, 'status' => 'failed', 'exit_code' => 1, 'host' => 'web-1',
        'exception_class' => RuntimeException::class, 'fingerprint' => str_repeat('a', 40), 'output' => 'secret',
    ]);

    $tasks = apiWidget($this->getJson('/spyglass/api/schedule')->assertOk()->json(), 'tasks');

    expect($tasks)->toMatchArray(['type' => 'table', 'link' => 'schedule/{key}', 'pagination' => null])
        ->and(collect($tasks['columns'])->firstWhere('key', 'last_status')['tones'])->toMatchArray(['success' => 'ok', 'failed' => 'error'])
        ->and($tasks['rows'][0])->toMatchArray([
            'key' => sha1('inspire'),
            'last_status' => 'failed',
            'last_started_at' => '2026-01-01T12:00:00.250Z',
            'last_duration_ms' => 1000,
            'next_run' => '2026-01-01T12:01:00.000Z',
            'missed' => false,
        ])
        ->and($tasks['rows'][0]['recent'][array_key_last($tasks['rows'][0]['recent'])])->toBe(['status' => 'failed', 'started_at' => '2026-01-01T12:00:00.250Z'])
        // Only declared columns and the values links need.
        ->and($tasks['rows'][0])->not->toHaveKey('command');

    $page = $this->getJson('/spyglass/api/schedule/'.sha1('inspire'))->assertOk()->json();

    expect($page['title'])->toBe('inspire')
        ->and(apiItems($page, 'task')['expression'])->toBe('* * * * *')
        ->and(apiWidget($page, 'runs'))->toMatchArray(['pagination' => ['page' => 1, 'per_page' => 25, 'has_more' => false, 'total' => null]])
        ->and(apiWidget($page, 'runs')['rows'][0])->toMatchArray([
            'status' => 'failed',
            'host' => 'web-1',
            'duration_ms' => 1000,
            'exception' => ['label' => RuntimeException::class, 'url' => 'exceptions/'.str_repeat('a', 40)],
        ]);

    $this->getJson('/spyglass/api/schedule/missing')->assertNotFound();
});

it('describes the queue', function () {
    // Bucket-aligned: the last hour still holds the 11:00 bucket at 12:04.
    Date::setTestNow('2026-01-01 12:04:00');
    DB::table(QueueModule::AGGREGATES)->insert([
        ['bucket' => '2026-01-01 11:00:00', 'connection' => 'database', 'queue' => 'default', 'job' => 'A', 'processed' => 4, 'failed' => 1, 'duration_max' => 30],
        ['bucket' => '2026-01-01 11:05:00', 'connection' => 'database', 'queue' => 'emails', 'job' => 'B', 'processed' => 2, 'failed' => 0, 'duration_max' => 10],
    ]);
    DB::table(QueueModule::FAILED_JOBS)->insert([
        'uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default', 'job' => 'A', 'attempts' => 1,
        'exception_class' => 'E', 'fingerprint' => str_repeat('a', 40), 'failed_at' => '2026-01-01 11:00:00', 'scope' => 'tenant:acme', 'context' => '{"tenant":"acme"}',
    ]);
    Spyglass::resolveScopeLabelsUsing(fn (array $scopes) => ['tenant:acme' => 'Acme']);

    $page = $this->getJson('/spyglass/api/queue?range=1h')->assertOk()->json();

    expect(apiWidget($page, 'processed'))->toBe(['type' => 'metric', 'key' => 'processed', 'label' => 'Processed', 'value' => 6, 'filters' => ['range', 'queue', 'job'], 'previous' => 0])
        ->and(apiWidget($page, 'throughput')['points'])->toHaveCount(2)
        ->and(apiWidget($page, 'throughput')['points'][0])->toMatchArray(['bucket' => '2026-01-01T11:00:00.000Z', 'processed' => 4, 'failed' => 1])
        ->and(apiWidget($page, 'jobs')['rows'][0])->toMatchArray(['job' => 'A', 'connection' => 'database', 'queue' => 'default'])
        ->and(apiWidget($page, 'sizes')['rows'])->toHaveCount(2)
        ->and(apiWidget($page, 'failed_jobs'))->toMatchArray([
            'link' => 'queue/failed-jobs/{uuid}',
            'pagination' => ['page' => 1, 'per_page' => 25, 'has_more' => false, 'total' => 1],
            'filters' => ['queue', 'job', 'scope'],
        ])
        ->and(apiWidget($page, 'failed_jobs')['rows'][0])->toBe([
            'uuid' => 'u-1',
            'failed_at' => '2026-01-01T11:00:00.000Z',
            'job' => 'A',
            'connection' => 'database',
            'queue' => 'default',
            'exception' => ['label' => 'E', 'url' => 'exceptions/'.str_repeat('a', 40)],
            'attempts' => 1,
            'scope_label' => 'Acme',
        ])
        ->and(collect($page['filters'])->pluck('key')->all())->toBe(['range', 'queue', 'job', 'scope']);

    $filtered = $this->getJson('/spyglass/api/queue?queue=emails&range=7d')->assertOk()->json();

    expect(apiWidget($filtered, 'processed')['value'])->toBe(2)
        ->and(array_column(apiWidget($filtered, 'sizes')['rows'], 'queue'))->toBe(['emails'])
        ->and(apiWidget($filtered, 'failed_jobs')['rows'])->toBe([])
        ->and($filtered['filters'][0]['value'])->toBe('7d');

    // Aggregates without scopes ignore the scope filter instead of showing zeros.
    $scoped = $this->getJson('/spyglass/api/queue?scope=tenant:other')->assertOk()->json();

    expect(apiWidget($scoped, 'processed')['value'])->toBe(6)
        ->and(apiWidget($scoped, 'failed_jobs')['rows'])->toBe([]);

    config(['spyglass.modules.queue.aggregate_by_scope' => true]);
    $scoped = $this->getJson('/spyglass/api/queue?scope=tenant:other')->assertOk()->json();

    expect(apiWidget($scoped, 'processed'))->toMatchArray(['value' => 0, 'filters' => ['range', 'queue', 'job', 'scope']]);

    $this->getJson('/spyglass/api/queue?range=1y')->assertUnprocessable();
    $this->getJson('/spyglass/api/queue?queue[]=x')->assertUnprocessable();
});

it('shows a failed job with its index row and exception group', function () {
    // Laravel applies `app.timezone` at boot.
    config(['app.timezone' => 'Europe/Rome']);
    date_default_timezone_set('Europe/Rome');
    $this->beforeApplicationDestroyed(fn () => date_default_timezone_set('UTC'));
    Date::setTestNow(Date::parse('2026-01-01 11:00:00', 'UTC'));
    $uuid = apiFailJob();
    DB::table(QueueModule::FAILED_JOBS)->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'job' => QueueJob::class, 'attempts' => 1,
        'exception_class' => RuntimeException::class, 'fingerprint' => str_repeat('a', 40), 'failed_at' => '2026-01-01 11:00:00',
    ]);

    $page = $this->getJson("/spyglass/api/queue/failed-jobs/{$uuid}")->assertOk()->json();
    $items = apiItems($page, 'job');

    expect($items)->toMatchArray([
        'uuid' => $uuid,
        'job' => QueueJob::class,
        'attempts' => 1,
        'failed_at' => '2026-01-01T11:00:00.000Z',
        'exception_group' => ['label' => RuntimeException::class, 'url' => 'exceptions/'.str_repeat('a', 40)],
    ])
        ->and($items['payload'])->toBeArray()->toHaveKey('uuid', $uuid)
        ->and(collect(apiWidget($page, 'job')['actions'])->pluck('url')->all())->toBe(["queue/failed-jobs/{$uuid}/retry", "queue/failed-jobs/{$uuid}/delete"]);

    config(['spyglass.modules.exceptions.enabled' => false]);

    expect(apiItems($this->getJson("/spyglass/api/queue/failed-jobs/{$uuid}")->json(), 'job')['exception_group'])
        ->toBe(['label' => RuntimeException::class, 'url' => null]);

    $this->getJson('/spyglass/api/queue/failed-jobs/missing')->assertNotFound()->assertJson(['message' => 'Already retried or removed.']);
});

it('retries a failed job', function () {
    $uuid = apiFailJob();

    $this->postJson("/spyglass/api/queue/failed-jobs/{$uuid}/retry")->assertOk()->assertExactJson(['outcome' => 'done', 'message' => null]);

    expect(app('queue.failer')->find($uuid))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->postJson("/spyglass/api/queue/failed-jobs/{$uuid}/retry")->assertNotFound()->assertExactJson(['outcome' => 'missing', 'message' => 'Already retried or removed.']);
});

it('deletes a failed job', function () {
    $uuid = apiFailJob();

    $this->postJson("/spyglass/api/queue/failed-jobs/{$uuid}/delete")->assertOk()->assertExactJson(['outcome' => 'done', 'message' => null]);

    expect(app('queue.failer')->find($uuid))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0);

    $this->postJson("/spyglass/api/queue/failed-jobs/{$uuid}/delete")->assertNotFound()->assertExactJson(['outcome' => 'missing', 'message' => 'Already retried or removed.']);
});

it('reports a failed retry or delete as a conflict', function (string $action, string $command) {
    $uuid = apiFailJob();
    Artisan::registerCommand(new ClosureCommand("queue:{$command} {id*}", fn () => 1));

    $this->postJson("/spyglass/api/queue/failed-jobs/{$uuid}/{$action}")->assertConflict()->assertExactJson(['outcome' => 'failed', 'message' => 'The command failed.']);

    expect(app('queue.failer')->find($uuid))->not->toBeNull();
})->with([['retry', 'retry'], ['delete', 'forget']]);

it('describes exceptions', function () {
    seedExceptionGroup(str_repeat('a', 40), ['line' => 12, 'count' => 3, 'last_seen_at' => '2026-03-01 12:00:00']);
    seedExceptionGroup(str_repeat('b', 40), ['class' => LogicException::class, 'resolved_at' => '2026-03-02 00:00:00']);

    $groups = apiWidget($this->getJson('/spyglass/api/exceptions')->assertOk()->json(), 'groups');

    expect($groups['link'])->toBe('exceptions/{fingerprint}')
        ->and(collect($groups['columns'])->firstWhere('key', 'status')['tones'])->toBe(['unresolved' => 'error', 'resolved' => 'ok'])
        ->and($groups['rows'][0])->toMatchArray(['location' => 'app/Foo.php:12', 'count' => 3, 'status' => 'unresolved', 'last_seen_at' => '2026-03-01T12:00:00.000Z'])
        ->and($groups['rows'][1])->toMatchArray(['fingerprint' => str_repeat('b', 40), 'status' => 'resolved']);

    $unresolved = apiWidget($this->getJson('/spyglass/api/exceptions?status=unresolved')->json(), 'groups');

    expect(array_column($unresolved['rows'], 'fingerprint'))->toBe([str_repeat('a', 40)]);

    $this->getJson('/spyglass/api/exceptions?status=open')->assertUnprocessable();
});

it('shows an exception group with its sources and occurrences', function () {
    $fingerprint = str_repeat('a', 40);
    seedExceptionGroup($fingerprint);
    DB::table(Sources::GROUPS)->insert(['kind' => 'exception', 'signature' => $fingerprint, 'source_type' => 'job', 'source_hash' => sha1('A'), 'source' => 'A', 'count' => 3, 'last_seen_at' => '2026-03-01 12:00:00']);
    DB::table(Sources::ENTRIES)->insert([
        'kind' => 'exception', 'signature' => $fingerprint, 'recorded_at' => '2026-03-01 12:00:00', 'message' => 'Boom',
        'body' => '[{"file":"app/Foo.php","line":12,"function":"handle"}]', 'source_type' => 'job', 'source' => 'A',
    ]);

    $page = $this->getJson("/spyglass/api/exceptions/{$fingerprint}")->assertOk()->json();

    expect(apiWidget($page, 'group')['actions'])->toBe([['key' => 'resolve', 'label' => 'Resolve', 'url' => "exceptions/{$fingerprint}/resolve"]])
        ->and(apiWidget($page, 'sources')['rows'][0])->toMatchArray(['source_type' => 'job', 'source' => 'A', 'count' => 3])
        ->and(apiWidget($page, 'occurrences')['rows'][0]['trace'])->toBe([['file' => 'app/Foo.php', 'line' => 12, 'function' => 'handle']]);

    $this->getJson('/spyglass/api/exceptions/'.str_repeat('f', 40))->assertNotFound();
});

it('resolves and reopens an exception group', function () {
    $fingerprint = str_repeat('a', 40);
    seedExceptionGroup($fingerprint);

    $this->postJson("/spyglass/api/exceptions/{$fingerprint}/resolve")->assertNoContent();
    $this->postJson("/spyglass/api/exceptions/{$fingerprint}/resolve")->assertNoContent();

    expect(DB::table(Sources::GROUPS)->value('resolved_at'))->not->toBeNull()
        ->and(apiWidget($this->getJson("/spyglass/api/exceptions/{$fingerprint}")->json(), 'group')['actions'][0]['key'])->toBe('reopen');

    $this->postJson("/spyglass/api/exceptions/{$fingerprint}/reopen")->assertNoContent();

    expect(DB::table(Sources::GROUPS)->value('resolved_at'))->toBeNull();

    $this->postJson('/spyglass/api/exceptions/'.str_repeat('f', 40).'/resolve')->assertNotFound();
    $this->postJson('/spyglass/api/exceptions/'.str_repeat('f', 40).'/reopen')->assertNotFound();
});

it('describes logs and shows an entry with its sources', function () {
    Date::setTestNow('2026-03-01 12:30:00');
    $error = apiLogEntry('error', 'Disk full', '{"disk":"local"}');
    $warning = apiLogEntry('warning', 'Slow', '{"ms":', '2026-03-01 11:00:00');
    DB::table(Sources::GROUPS)->insert(['kind' => 'log', 'signature' => LogsModule::signature('error', 'Disk full'), 'source_type' => 'command', 'source_hash' => sha1('backup'), 'source' => 'backup', 'count' => 7, 'last_seen_at' => '2026-03-01 12:00:00']);

    $entries = apiWidget($this->getJson('/spyglass/api/logs')->assertOk()->json(), 'entries');

    expect($entries['link'])->toBe('logs/{id}')
        ->and(array_column($entries['rows'], 'message'))->toBe(['Disk full', 'Slow'])
        ->and($entries['rows'][0])->not->toHaveKeys(['log_context', 'context'])
        ->and(collect($entries['columns'])->firstWhere('key', 'level')['tones'])->toMatchArray(['error' => 'error', 'warning' => 'warning', 'info' => 'info'])
        ->and(array_column(apiWidget($this->getJson('/spyglass/api/logs?level=warning')->json(), 'entries')['rows'], 'message'))->toBe(['Slow']);

    $page = $this->getJson("/spyglass/api/logs/{$error}")->assertOk()->json();

    expect(apiItems($page, 'entry'))->toMatchArray(['log_context' => ['disk' => 'local'], 'context' => ['tenant' => 'a']])
        ->and(apiWidget($page, 'sources')['rows'][0])->toMatchArray(['source' => 'backup', 'count' => 7]);

    // A context cut at its length limit is shown as stored.
    expect(apiItems($this->getJson("/spyglass/api/logs/{$warning}")->json(), 'entry')['log_context'])->toBe('{"ms":');

    $this->getJson('/spyglass/api/logs/999')->assertNotFound();
});

it('describes health checks, with the unit of each measure', function () {
    $checks = collect(apiWidget($this->getJson('/spyglass/api/health')->assertOk()->json(), 'checks')['rows']);

    $value = fn (string $prefix) => $checks->first(fn (array $check) => str_starts_with($check['label'], $prefix))['value'] ?? null;

    expect($checks->firstWhere('label', 'Environment'))->toMatchArray(['status' => 'info', 'value' => 'testing'])
        ->and($checks->firstWhere('label', 'Database '.config('database.default'))['status'] ?? null)->toBe('ok')
        ->and($value('Database '))->toMatch('/^\d+(\.\d)? ms$/')
        ->and($value('Cache '))->toMatch('/^\d+(\.\d)? ms$/')
        ->and($value('Queue '))->toBe('0 pending');
});

it('suggests known values for the free text filters', function () {
    Spyglass::resolveScopeLabelsUsing(fn (array $scopes) => array_combine($scopes, array_map(strtoupper(...), $scopes)));
    Date::setTestNow('2026-01-01 12:04:00');
    DB::table(QueueModule::AGGREGATES)->insert(['bucket' => '2026-01-01 11:00:00', 'connection' => 'database', 'queue' => 'emails', 'job' => 'A', 'processed' => 1]);
    DB::table(QueueModule::FAILED_JOBS)->insert([
        'uuid' => 'u-1', 'connection' => 'database', 'queue' => 'emails', 'job' => 'A', 'attempts' => 1,
        'exception_class' => 'E', 'fingerprint' => str_repeat('a', 40), 'failed_at' => '2026-01-01 11:00:00', 'scope' => 'tenant:acme',
    ]);
    seedExceptionGroup(str_repeat('a', 40), scopes: ['tenant:acme']);
    apiLogEntry('error', 'Disk full');

    $suggestions = fn (string $uri) => collect($this->getJson($uri)->assertOk()->json('filters'))->pluck('suggestions', 'key')->all();

    expect($suggestions('/spyglass/api/queue'))->toMatchArray([
        'range' => null,
        'queue' => [['value' => 'emails', 'label' => 'emails']],
        'job' => [['value' => 'A', 'label' => 'A']],
        'scope' => [['value' => 'tenant:acme', 'label' => 'TENANT:ACME']],
    ])
        ->and($suggestions('/spyglass/api/exceptions'))->toMatchArray([
            'class' => [['value' => RuntimeException::class, 'label' => RuntimeException::class]],
            'scope' => [['value' => 'tenant:acme', 'label' => 'TENANT:ACME']],
        ])
        ->and($suggestions('/spyglass/api/logs'))->toMatchArray(['scope' => []]);
});

it('compares queue counters with the period just before, without counting the bucket between them twice', function () {
    Date::setTestNow('2026-01-01 12:04:00');
    // The last hour starts with the 11:00 bucket: the hour before ends just before it.
    DB::table(QueueModule::AGGREGATES)->insert([
        ['bucket' => '2026-01-01 10:00:00', 'connection' => 'database', 'queue' => 'default', 'job' => 'A', 'processed' => 2, 'failed' => 3, 'duration_sum' => 500, 'duration_max' => 400],
        ['bucket' => '2026-01-01 11:00:00', 'connection' => 'database', 'queue' => 'default', 'job' => 'A', 'processed' => 4, 'failed' => 1, 'duration_sum' => 100, 'duration_max' => 30],
    ]);

    $page = $this->getJson('/spyglass/api/queue?range=1h')->assertOk()->json();

    expect(apiWidget($page, 'processed'))->toMatchArray(['value' => 4, 'previous' => 2])
        ->and(apiWidget($page, 'processed'))->not->toHaveKey('better')
        ->and(apiWidget($page, 'failed'))->toMatchArray(['value' => 1, 'previous' => 3, 'better' => 'down'])
        ->and(apiWidget($page, 'duration_avg'))->toMatchArray(['value' => 20, 'previous' => 100, 'format' => 'duration'])
        ->and(apiWidget($page, 'duration_max'))->toMatchArray(['value' => 30, 'previous' => 400]);

    // The week before is past the retention of the aggregates.
    expect($this->getJson('/spyglass/api/queue?range=7d')->json())->not->toHaveKey('widgets.0.previous');
});

it('counts recorded exceptions and log entries per hour, with every hour present', function () {
    Date::setTestNow('2026-03-01 12:30:00');
    apiLogEntry('error', 'Disk full', at: '2026-03-01 12:10:00');
    apiLogEntry('critical', 'Disk gone', at: '2026-03-01 12:20:00');
    apiLogEntry('warning', 'Slow', at: '2026-03-01 10:59:59');
    apiLogEntry('warning', 'Too old', at: '2026-02-27 12:00:00');

    $chart = apiWidget($this->getJson('/spyglass/api/logs')->assertOk()->json(), 'recorded');

    expect($chart)->toMatchArray(['style' => 'bars', 'filters' => ['range', 'level', 'scope']])
        ->and($chart['points'])->toHaveCount(24)
        ->and($chart['points'][0]['bucket'])->toBe('2026-02-28T13:00:00.000Z')
        ->and($chart['points'][22])->toBe(['bucket' => '2026-03-01T11:00:00.000Z', 'error' => 0, 'warning' => 0, 'info' => 0])
        ->and($chart['points'][21])->toMatchArray(['warning' => 1])
        ->and($chart['points'][23])->toMatchArray(['error' => 2, 'warning' => 0]);

    $fingerprint = str_repeat('a', 40);
    seedExceptionGroup($fingerprint, scopes: ['tenant:a', 'tenant:b']);
    $total = fn (string $query) => array_sum(array_column(apiWidget($this->getJson("/spyglass/api/exceptions?range=7d{$query}")->assertOk()->json(), 'recorded')['points'], 'count'));

    expect(apiWidget($this->getJson('/spyglass/api/exceptions?range=7d')->json(), 'recorded')['points'])->toHaveCount(168)
        ->and($total(''))->toBe(2)
        ->and($total('&scope=tenant:a'))->toBe(1)
        ->and($total('&class='.RuntimeException::class))->toBe(2)
        ->and($total('&class=Missing'))->toBe(0);
});

it('counts entries per hour when the application uses immutable dates', function () {
    Date::use(CarbonImmutable::class);

    try {
        Date::setTestNow('2026-03-01 12:30:00');
        apiLogEntry('error', 'Disk full', at: '2026-03-01 12:10:00');

        expect(apiWidget($this->getJson('/spyglass/api/logs')->assertOk()->json(), 'recorded')['points'])->toHaveCount(24);
    } finally {
        Date::useDefault();
    }
});
