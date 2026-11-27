<?php

use Illuminate\Support\Facades\Gate;
use Keepsuit\Spyglass\Facades\Spyglass;
use Keepsuit\Spyglass\Http\Middleware\Authorize;

it('denies access outside the local environment by default', function () {
    $this->get('/spyglass')->assertForbidden();
});

it('allows access in the local environment by default', function () {
    $this->app['env'] = 'local';

    $this->get('/spyglass')->assertOk();
});

it('uses the viewSpyglass gate defined by the application', function () {
    Gate::define('viewSpyglass', fn ($user = null) => true);

    $this->get('/spyglass')->assertOk();
});

it('can be overridden with an auth callback', function () {
    $this->app['env'] = 'local';
    Spyglass::auth(fn () => false);

    $this->get('/spyglass')->assertForbidden();

    Spyglass::auth(fn () => true);
    $this->app['env'] = 'staging';

    $this->get('/spyglass')->assertOk();
});

it('registers the routes under the configured path and middleware', function () {
    $route = $this->bootApplication([
        'spyglass.path' => 'admin/monitor',
        'spyglass.middleware' => ['web', 'auth'],
    ])['router']->getRoutes()->getByName('spyglass.index');

    expect($route->uri())->toBe('admin/monitor')
        ->and($route->middleware())->toBe(['web', 'auth', Authorize::class]);
});
