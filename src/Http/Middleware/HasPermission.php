<?php

namespace Sanjid29\StarterCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HasPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! auth()->check()) {
            abort(403, 'Unauthenticated.');
        }

        if (! auth()->user()->can($permission)) {
            abort(403, 'You do not have permission to access this resource.');
        }

        return $next($request);
    }
}
