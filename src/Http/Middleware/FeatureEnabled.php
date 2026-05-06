<?php

namespace Sanjid29\StarterCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FeatureEnabled
{
    /**
     * Abort with 404 if the given feature flag is disabled.
     *
     * Usage in routes: ->middleware('feature:notifications')
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $enabled = function_exists('setting')
            ? setting("features.{$feature}", false)
            : config("starter-core.features.{$feature}", false);

        abort_unless($enabled, 404);

        return $next($request);
    }
}
