<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChangeNotRequired
{
    public function handle(Request $request, Closure $next, string $guard): Response
    {
        $user = Auth::guard($guard)->user();

        if ($user && $user->must_change_password) {
            return redirect()->route("{$guard}.password.edit");
        }

        return $next($request);
    }
}
