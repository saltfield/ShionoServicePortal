<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\CredentialAuthenticator;
use App\Domains\Auth\Services\LoginFlowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.admin.login');
    }

    public function store(AdminLoginRequest $request, LoginFlowService $loginFlow): RedirectResponse
    {
        return $loginFlow->handleLogin(UserType::Admin, 'admin', $request->validated());
    }

    public function destroy(Request $request, CredentialAuthenticator $authenticator, LoginFlowService $loginFlow): RedirectResponse
    {
        $authenticator->logout('admin');
        $loginFlow->clearPending('admin');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
