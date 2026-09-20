<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuardAuthenticated
{
    public function handle(Request $request, Closure $next, string $guard): Response
    {
        if (! Auth::guard($guard)->check()) {
            return redirect()->guest(route("{$guard}.login"));
        }

        return $next($request);
    }
}
