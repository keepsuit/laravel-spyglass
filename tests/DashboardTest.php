<?php

use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Keepsuit\Spyglass\Facades\Spyglass;

function dashboardAssets(): array
{
    $manifest = json_decode(file_get_contents(__DIR__.'/../dist/.vite/manifest.json'), true);
    $entry = $manifest['resources/js/main.tsx'];

    return ['js' => $entry['file'], 'css' => $entry['css'][0]];
}

beforeEach(function () {
    Gate::define('viewSpyglass', fn ($user = null) => true);
});

it('serves the application shell on the dashboard paths', function (string $path) {
    $assets = dashboardAssets();

    $this->get($path)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('data-base="/spyglass"', false)
        ->assertSee("src=\"/spyglass/{$assets['js']}\"", false)
        ->assertSee("href=\"/spyglass/{$assets['css']}\"", false);
})->with(['/spyglass', '/spyglass/queue', '/spyglass/queue/failed-jobs/0b1c', '/spyglass/apis']);

it('passes the CSRF token to the application', function () {
    $this->withSession([])->get('/spyglass')->assertSee('data-csrf="'.csrf_token().'"', false);
});

it('leaves unknown API paths to the API', function () {
    $this->get('/spyglass/api/missing')->assertNotFound()->assertDontSee('id="spyglass"', false);
});

it('serves the built assets with a long-lived cache', function (string $type, string $contentType) {
    $response = $this->get('/spyglass/'.dashboardAssets()[$type])
        ->assertOk()
        ->assertHeader('Content-Type', $contentType);

    expect($response->headers->getCacheControlDirective('max-age'))->toBe('31536000')
        ->and($response->headers->hasCacheControlDirective('immutable'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('private'))->toBeTrue();
})->with([['js', 'text/javascript; charset=utf-8'], ['css', 'text/css; charset=utf-8']]);

it('serves only the files of the build', function (string $file) {
    $this->get('/spyglass/assets/'.$file)->assertNotFound();
})->with(['missing.js', '..%2F.vite%2Fmanifest.json', '..%2F..%2Fcomposer.json']);

it('denies the shell and the assets without authorization', function () {
    Spyglass::auth(fn () => false);

    $this->get('/spyglass')->assertForbidden();
    $this->get('/spyglass/queue')->assertForbidden();
    $this->get('/spyglass/'.dashboardAssets()['js'])->assertForbidden();
});

it('serves the shell and the assets under a custom path', function () {
    $app = $this->bootApplication(['spyglass.path' => 'admin/monitor']);
    $app->make(Keepsuit\Spyglass\Spyglass::class)->auth(fn () => true);
    $assets = dashboardAssets();
    // Helpers such as `route()` resolve from the global container.
    Container::setInstance($app);

    try {
        $handle = fn (string $uri) => $app->make(Kernel::class)->handle(Request::create($uri));
        $shell = $handle('/admin/monitor/exceptions');

        expect($shell->getStatusCode())->toBe(200)
            ->and($shell->getContent())->toContain('data-base="/admin/monitor"', "src=\"/admin/monitor/{$assets['js']}\"")
            ->and($handle("/admin/monitor/{$assets['js']}")->getStatusCode())->toBe(200)
            ->and($handle('/spyglass')->getStatusCode())->toBe(404);
    } finally {
        Container::setInstance($this->app);
    }
});
