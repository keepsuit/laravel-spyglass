<?php

namespace Keepsuit\Spyglass\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Keepsuit\Spyglass\Spyglass;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        // At request time, not when routes are registered: cached routes outlive config changes.
        abort_unless(isset(app(Spyglass::class)->moduleClasses()[$module]), 404);

        return $next($request);
    }
}
