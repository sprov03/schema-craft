<?php

namespace SchemaCraft\Visualizer;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireLocalEnvironment
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local', 'testing')) {
            abort(404);
        }

        return $next($request);
    }
}
