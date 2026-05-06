<?php

namespace Sanjid29\StarterCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class HandleImpersonation
{
    /**
     * If an impersonation session is active, authenticate as the target user
     * for this request only (without touching the session's real auth state).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('impersonating.as') && Auth::check()) {
            $targetId = $request->session()->get('impersonating.as');
            $userModel = config('auth.providers.users.model');

            if ($target = $userModel::find($targetId)) {
                Auth::onceUsingId($target->id);
            }
        }

        return $next($request);
    }
}
