<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Auth\Services\PasswordChangeService;
use App\Domains\Auth\Support\GuardAwareRedirect;
use App\Http\Requests\Auth\PasswordChangeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

trait HandlesForcedPasswordChange
{
    abstract protected function guardName(): string;

    public function editPassword(Request $request): View
    {
        $user = $request->user($this->guardName());
        $forced = (bool) $user?->must_change_password;

        return view($forced ? 'auth.password.force-change' : 'auth.password.change', [
            'guard' => $this->guardName(),
            'forced' => $forced,
            'updateRoute' => route($this->guardName().'.password.update'),
            'logoutRoute' => route($this->guardName().'.logout'),
            'dashboardRoute' => route($this->guardName().'.dashboard'),
        ]);
    }

    public function updatePassword(
        PasswordChangeRequest $request,
        PasswordChangeService $passwordChange,
    ): RedirectResponse {
        $user = $request->user($this->guardName());
        $wasForced = (bool) $user->must_change_password;

        $passwordChange->change(
            $user,
            $request->validated('password'),
            $request->validated('current_password') ?? null,
        );

        if ($wasForced) {
            return GuardAwareRedirect::intended(
                $this->guardName(),
                route($this->guardName().'.dashboard'),
            )->with('status', 'パスワードを変更しました。');
        }

        return redirect()
            ->route($this->guardName().'.password.edit')
            ->with('status', 'パスワードを変更しました。');
    }
}
