<?php

namespace App\Http\Middleware;

use App\Domains\Iam\Services\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function handle(Request $request, Closure $next, string $permission, string $guard = 'admin'): Response
    {
        $user = Auth::guard($guard)->user();

        if ($user === null || ! $this->authorization->can($user, $permission)) {
            abort(403, 'この操作を行う権限がありません。');
        }

        return $next($request);
    }
}
