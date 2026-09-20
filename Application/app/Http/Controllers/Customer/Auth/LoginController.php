<?php

namespace App\Http\Controllers\Customer\Auth;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\CredentialAuthenticator;
use App\Domains\Auth\Services\LoginFlowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.customer.login');
    }

    public function store(CustomerLoginRequest $request, LoginFlowService $loginFlow): RedirectResponse
    {
        return $loginFlow->handleLogin(UserType::Customer, 'customer', $request->validated());
    }

    public function destroy(Request $request, CredentialAuthenticator $authenticator, LoginFlowService $loginFlow): RedirectResponse
    {
        $authenticator->logout('customer');
        $loginFlow->clearPending('customer');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('customer.login');
    }
}
