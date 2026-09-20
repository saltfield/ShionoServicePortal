<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'auth.guard' => \App\Http\Middleware\EnsureGuardAuthenticated::class,
            'guest.guard' => \App\Http\Middleware\RedirectIfGuardAuthenticated::class,
            'password.changed' => \App\Http\Middleware\EnsurePasswordChangeNotRequired::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);

        $middleware->redirectGuestsTo(function (\Illuminate\Http\Request $request) {
            if ($request->is('admin') || $request->is('admin/*')) {
                return route('admin.login');
            }
            if ($request->is('bp') || $request->is('bp/*')) {
                return route('bp.login');
            }
            if ($request->is('customer') || $request->is('customer/*')) {
                return route('customer.login');
            }

            return '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
