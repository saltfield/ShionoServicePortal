<?php

namespace App\Domains\Auth\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Session;

final class GuardAwareRedirect
{
    /**
     * Follow url.intended only when it belongs to the same area guard.
     * Always clears the shared intended key to avoid cross-guard leaks.
     */
    public static function intended(string $guard, string $defaultUrl): RedirectResponse
    {
        $intended = Session::pull('url.intended');

        if (is_string($intended) && self::belongsToGuard($intended, $guard)) {
            return redirect()->to($intended);
        }

        return redirect()->to($defaultUrl);
    }

    public static function belongsToGuard(string $url, string $guard): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return false;
        }

        return $path === '/'.$guard || str_starts_with($path, '/'.$guard.'/');
    }
}
