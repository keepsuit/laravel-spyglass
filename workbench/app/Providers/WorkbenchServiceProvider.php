<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Keepsuit\Spyglass\Facades\Spyglass;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewSpyglass', fn ($user = null) => true);

        config(['spyglass.context' => ['tenant_id']]);

        Spyglass::resolveScopeUsing(fn (array $context) => isset($context['tenant_id']) ? "tenant:{$context['tenant_id']}" : null);
        Spyglass::resolveScopeLabelsUsing(fn (array $scopes) => array_combine($scopes, array_map(
            fn (string $scope) => ucfirst(substr($scope, strlen('tenant:'))).' store',
            $scopes,
        )));
    }
}
