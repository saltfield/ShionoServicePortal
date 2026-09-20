<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfGuardAuthenticated
{
    public function handle(Request $request, Closure $next, string $guard): Response
    {
        if (Auth::guard($guard)->check()) {
            return redirect()->route("{$guard}.dashboard");
        }

        return $next($request);
    }
}
