<?php

namespace Keepsuit\Spyglass\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AcceptJson
{
    public function handle(Request $request, Closure $next): Response
    {
        // Validation errors would otherwise redirect a client that does not ask for JSON.
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
