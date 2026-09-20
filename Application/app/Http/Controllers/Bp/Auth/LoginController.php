<?php

namespace App\Http\Controllers\Bp\Auth;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\CredentialAuthenticator;
use App\Domains\Auth\Services\LoginFlowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\BpLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.bp.login');
    }

    public function store(BpLoginRequest $request, LoginFlowService $loginFlow): RedirectResponse
    {
        return $loginFlow->handleLogin(UserType::Bp, 'bp', $request->validated());
    }

    public function destroy(Request $request, CredentialAuthenticator $authenticator, LoginFlowService $loginFlow): RedirectResponse
    {
        $authenticator->logout('bp');
        $loginFlow->clearPending('bp');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('bp.login');
    }
}
