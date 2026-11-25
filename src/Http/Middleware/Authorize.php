<?php

namespace Keepsuit\Spyglass\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Keepsuit\Spyglass\Spyglass;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    public function __construct(protected Spyglass $spyglass) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->spyglass->check($request), 403);

        return $next($request);
    }
}
