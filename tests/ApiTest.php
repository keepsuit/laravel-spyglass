<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Http\Middleware\EnsureModuleEnabled;
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

function apiRequest(Route $route): array
{
    return [in_array('POST', $route->methods(), true) ? 'postJson' : 'getJson', '/'.preg_replace('/\{\w+\}/', '1', $route->uri())];
}

function apiWidget(array $page, string $key): array
{
    return collect($page['widgets'])->firstWhere('key', $key) ?? throw new RuntimeException("No widget [{$key}].");
}

function apiFailJob(): string
{
    QueueJob::dispatch('throw');
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);

    return app('queue.failer')->ids()[0];
}

function apiExceptionGroup(string $fingerprint, array $values = []): void
{
    DB::table(Sources::GROUPS)->insert([
        'kind' => 'exception', 'signature' => $fingerprint, 'class' => RuntimeException::class, 'message' => 'Boom',
        'file' => 'app/Foo.php', 'line' => 12, 'count' => 3,
        'first_seen_at' => '2026-03-01 10:00:00', 'last_seen_at' => '2026-03-01 12:00:00', ...$values,
    ]);
}

it('denies every endpoint without authorization', function () {
    Spyglass::auth(fn () => false);

    expect(apiRoutes())->toHaveCount(14);

    foreach (apiRoutes() as $route) {
        [$method, $uri] = apiRequest($route);

        $this->{$method}($uri)->assertForbidden();
    }
});

it('answers 404 on every endpoint of a disabled module and leaves it out of the module list', function (string $module) {
    config(["spyglass.modules.{$module}.enabled" => false]);

    $routes = array_filter(apiRoutes(), fn (Route $route) => explode('.', (string) $route->getName())[2] === $module);

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        [$method, $uri] = apiRequest($route);

        // Routes with parameters answer 404 for a missing record as well.
        expect($route->gatherMiddleware())->toContain(EnsureModuleEnabled::class.':'.$module);
        $this->{$method}($uri)->assertNotFound();
    }

    expect($this->getJson('/spyglass/api')->json('modules.*.key'))->not->toContain($module)->toContain('test');
})->with(['schedule', 'queue', 'exceptions', 'logs', 'health']);

it('checks the CSRF token of actions', function () {
    // CSRF is not verified while running unit tests.
    $this->app['env'] = 'local';
    apiExceptionGroup(str_repeat('a', 40));

    $this->post('/spyglass/api/exceptions/'.str_repeat('a', 40).'/resolve')->assertStatus(419);
    $this->get('/spyglass/api/exceptions/'.str_repeat('a', 40).'/resolve')->assertMethodNotAllowed();
});

it('lists the enabled modules', function () {
    expect($this->getJson('/spyglass/api')->assertOk()->json('modules'))->toContain(['key' => 'queue', 'label' => 'Queue']);
});

it('describes the schedule', function () {
    Date::setTestNow('2026-01-01 12:00:30');
    DB::table(ScheduleModule::TASKS)->insert([
        'key' => sha1('inspire'), 'label' => 'inspire', 'expression' => '* * * * *', 'timezone' => 'UTC',
        'first_seen_at' => '2025-01-01 00:00:00', 'last_seen_at' => '2026-01-01 12:00:00',
    ]);
    DB::table(ScheduleModule::RUNS)->insert([
        'id' => (string) Str::ulid(), 'task' => sha1('inspire'), 'started_at' => '2026-01-01 12:00:00.250',
        'finished_at' => '2026-01-01 12:00:01.250', 'duration_ms' => 1000, 'status' => 'success', 'exit_code' => 0, 'host' => 'web-1',
    ]);

    $tasks = apiWidget($this->getJson('/spyglass/api/schedule')->assertOk()->json(), 'tasks');

    expect($tasks)->toMatchArray(['type' => 'table', 'link' => 'schedule/{key}', 'pagination' => null])
        ->and($tasks['rows'][0])->toMatchArray([
            'key' => sha1('inspire'),
            'last_status' => 'success',
            'last_started_at' => '2026-01-01T12:00:00.250Z',
            'last_duration_ms' => 1000,
            'next_run' => '2026-01-01T12:01:00.000Z',
            'missed' => false,
        ]);

    $page = $this->getJson('/spyglass/api/schedule/'.sha1('inspire'))->assertOk()->json();

    expect($page['title'])->toBe('inspire')
        ->and(collect(apiWidget($page, 'task')['items'])->firstWhere('key', 'expression')['value'])->toBe('* * * * *')
        ->and(apiWidget($page, 'runs'))->toMatchArray(['pagination' => ['page' => 1, 'per_page' => 25, 'has_more' => false, 'total' => null]])
        ->and(apiWidget($page, 'runs')['rows'][0])->toMatchArray(['status' => 'success', 'host' => 'web-1', 'duration_ms' => 1000]);

    $this->getJson('/spyglass/api/schedule/missing')->assertNotFound();
});

it('describes the queue', function () {
    Date::setTestNow('2026-01-01 12:00:00');
    DB::table(QueueModule::AGGREGATES)->insert([
        ['bucket' => '2026-01-01 11:00:00', 'connection' => 'database', 'queue' => 'default', 'job' => 'A', 'processed' => 4, 'failed' => 1, 'duration_max' => 30],
        ['bucket' => '2026-01-01 11:05:00', 'connection' => 'database', 'queue' => 'emails', 'job' => 'B', 'processed' => 2, 'failed' => 0, 'duration_max' => 10],
    ]);
    DB::table(QueueModule::FAILED_JOBS)->insert([
        'uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default', 'job' => 'A', 'attempts' => 1,
        'exception_class' => 'E', 'fingerprint' => str_repeat('a', 40), 'failed_at' => '2026-01-01 11:00:00', 'scope' => 'tenant:acme',
    ]);
    Spyglass::resolveScopeLabelsUsing(fn (array $scopes) => ['tenant:acme' => 'Acme']);

    $page = $this->getJson('/spyglass/api/queue')->assertOk()->json();

    expect(apiWidget($page, 'processed'))->toBe(['type' => 'metric', 'key' => 'processed', 'label' => 'Processed', 'value' => 6, 'unit' => null])
        ->and(apiWidget($page, 'throughput')['points'])->toHaveCount(2)
        ->and(apiWidget($page, 'throughput')['points'][0])->toMatchArray(['bucket' => '2026-01-01T11:00:00.000Z', 'processed' => 4, 'failed' => 1])
        ->and(apiWidget($page, 'jobs')['rows'])->toHaveCount(2)
        ->and(apiWidget($page, 'failed_jobs'))->toMatchArray([
            'link' => 'queue/failed-jobs/{uuid}',
            'pagination' => ['page' => 1, 'per_page' => 25, 'has_more' => false, 'total' => 1],
        ])
        ->and(apiWidget($page, 'failed_jobs')['rows'][0])->toMatchArray(['uuid' => 'u-1', 'scope' => 'tenant:acme', 'scope_label' => 'Acme', 'failed_at' => '2026-01-01T11:00:00.000Z'])
        ->and(collect($page['filters'])->pluck('key')->all())->toBe(['range', 'queue', 'job', 'scope']);

    $filtered = $this->getJson('/spyglass/api/queue?queue=emails&range=7d')->assertOk()->json();

    expect(apiWidget($filtered, 'processed')['value'])->toBe(2)
        ->and(apiWidget($filtered, 'failed_jobs')['rows'])->toBe([])
        ->and($filtered['filters'][0]['value'])->toBe('7d');

    $this->getJson('/spyglass/api/queue?range=1y')->assertUnprocessable();
    $this->getJson('/spyglass/api/queue?queue[]=x')->assertUnprocessable();
});

it('shows a failed job', function () {
    // Laravel applies `app.timezone` at boot.
    config(['app.timezone' => 'Europe/Rome']);
    date_default_timezone_set('Europe/Rome');
    $this->beforeApplicationDestroyed(fn () => date_default_timezone_set('UTC'));
    Date::setTestNow(Date::parse('2026-01-01 11:00:00', 'UTC'));
    $uuid = apiFailJob();

    $details = apiWidget($this->getJson("/spyglass/api/queue/failed-jobs/{$uuid}")->assertOk()->json(), 'job');
    $items = collect($details['items'])->pluck('value', 'key');

    expect($items['uuid'])->toBe($uuid)
        ->and($items['failed_at'])->toBe('2026-01-01T11:00:00.000Z')
        ->and(collect($details['items'])->firstWhere('key', 'payload')['value'])->toBeArray()->toHaveKey('uuid', $uuid)
        ->and(collect($details['actions'])->pluck('url')->all())->toBe(["queue/failed-jobs/{$uuid}/retry", "queue/failed-jobs/{$uuid}/delete"]);

    $this->getJson('/spyglass/api/queue/failed-jobs/missing')->assertNotFound();
});

it('retries a failed job', function () {
    $uuid = apiFailJob();

    $this->postJson("/spyglass/api/queue/failed-jobs/{$uuid}/retry")->assertOk()->assertExactJson(['outcome' => 'done']);

    expect(app('queue.failer')->find($uuid))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->postJson("/spyglass/api/queue/failed-jobs/{$uuid}/retry")->assertNotFound()->assertExactJson(['outcome' => 'missing']);
});

it('deletes a failed job', function () {
    $uuid = apiFailJob();

    $this->postJson("/spyglass/api/queue/failed-jobs/{$uuid}/delete")->assertOk()->assertExactJson(['outcome' => 'done']);

    expect(app('queue.failer')->find($uuid))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0);

    $this->postJson("/spyglass/api/queue/failed-jobs/{$uuid}/delete")->assertNotFound()->assertExactJson(['outcome' => 'missing']);
});

it('describes exceptions', function () {
    apiExceptionGroup(str_repeat('a', 40));
    apiExceptionGroup(str_repeat('b', 40), ['class' => LogicException::class, 'resolved_at' => '2026-03-02 00:00:00']);

    $groups = apiWidget($this->getJson('/spyglass/api/exceptions')->assertOk()->json(), 'groups');

    expect($groups['link'])->toBe('exceptions/{fingerprint}')
        ->and($groups['rows'][0])->toMatchArray(['fingerprint' => str_repeat('b', 40), 'status' => 'resolved'])
        ->and($groups['rows'][1])->toMatchArray(['location' => 'app/Foo.php:12', 'count' => 3, 'status' => 'unresolved', 'last_seen_at' => '2026-03-01T12:00:00.000Z']);

    $unresolved = apiWidget($this->getJson('/spyglass/api/exceptions?status=unresolved')->json(), 'groups');

    expect(array_column($unresolved['rows'], 'fingerprint'))->toBe([str_repeat('a', 40)]);

    $this->getJson('/spyglass/api/exceptions?status=open')->assertUnprocessable();
});

it('shows an exception group with its sources and occurrences', function () {
    $fingerprint = str_repeat('a', 40);
    apiExceptionGroup($fingerprint);
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
    apiExceptionGroup($fingerprint);

    $this->postJson("/spyglass/api/exceptions/{$fingerprint}/resolve")->assertNoContent();

    expect(DB::table(Sources::GROUPS)->value('resolved_at'))->not->toBeNull()
        ->and(apiWidget($this->getJson("/spyglass/api/exceptions/{$fingerprint}")->json(), 'group')['actions'][0]['key'])->toBe('reopen');

    $this->postJson("/spyglass/api/exceptions/{$fingerprint}/reopen")->assertNoContent();

    expect(DB::table(Sources::GROUPS)->value('resolved_at'))->toBeNull();

    $this->postJson('/spyglass/api/exceptions/'.str_repeat('f', 40).'/resolve')->assertNotFound();
    $this->postJson('/spyglass/api/exceptions/'.str_repeat('f', 40).'/reopen')->assertNotFound();
});

it('describes logs and shows an entry with its sources', function () {
    DB::table(Sources::ENTRIES)->insert([
        ['kind' => 'log', 'signature' => LogsModule::signature('error', 'Disk full'), 'level' => 'error', 'recorded_at' => '2026-03-01 12:00:00', 'message' => 'Disk full', 'body' => '{"disk":"local"}', 'source_type' => 'command', 'source' => 'backup'],
        ['kind' => 'log', 'signature' => LogsModule::signature('warning', 'Slow'), 'level' => 'warning', 'recorded_at' => '2026-03-01 11:00:00', 'message' => 'Slow', 'body' => '{"ms":', 'source_type' => 'command', 'source' => 'backup'],
    ]);
    DB::table(Sources::GROUPS)->insert(['kind' => 'log', 'signature' => LogsModule::signature('error', 'Disk full'), 'source_type' => 'command', 'source_hash' => sha1('backup'), 'source' => 'backup', 'count' => 7, 'last_seen_at' => '2026-03-01 12:00:00']);

    $entries = apiWidget($this->getJson('/spyglass/api/logs')->assertOk()->json(), 'entries');

    expect($entries['link'])->toBe('logs/{id}')
        ->and(array_column($entries['rows'], 'message'))->toBe(['Disk full', 'Slow'])
        ->and(array_column(apiWidget($this->getJson('/spyglass/api/logs?level=warning')->json(), 'entries')['rows'], 'message'))->toBe(['Slow']);

    $this->getJson('/spyglass/api/logs?level=loud')->assertUnprocessable();

    $page = $this->getJson("/spyglass/api/logs/{$entries['rows'][0]['id']}")->assertOk()->json();

    expect(collect(apiWidget($page, 'entry')['items'])->firstWhere('key', 'log_context')['value'])->toBe(['disk' => 'local'])
        ->and(apiWidget($page, 'sources')['rows'][0])->toMatchArray(['source' => 'backup', 'count' => 7]);

    // A context cut at its length limit is shown as stored.
    $truncated = $this->getJson("/spyglass/api/logs/{$entries['rows'][1]['id']}")->json();

    expect(collect(apiWidget($truncated, 'entry')['items'])->firstWhere('key', 'log_context')['value'])->toBe('{"ms":');

    $this->getJson('/spyglass/api/logs/999')->assertNotFound();
});

it('describes health checks', function () {
    $checks = apiWidget($this->getJson('/spyglass/api/health')->assertOk()->json(), 'checks');

    expect($checks['type'])->toBe('table')
        ->and(collect($checks['rows'])->firstWhere('key', 'environment'))->toMatchArray(['status' => 'info', 'value' => 'testing'])
        ->and(collect($checks['rows'])->firstWhere('key', 'database:'.config('database.default'))['status'] ?? null)->toBe('ok');
});
