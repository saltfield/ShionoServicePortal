<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Support\GuardAwareRedirect;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Session;

class LoginFlowService
{
    public function __construct(
        private readonly CredentialAuthenticator $authenticator,
        private readonly TwoFactorPolicyResolver $policyResolver,
    ) {}

    /**
     * @param  array{login_id: string, password: string, bpn?: string|null, cn?: string|null}  $credentials
     */
    public function handleLogin(UserType $expectedType, string $guard, array $credentials): RedirectResponse
    {
        $user = $this->authenticator->validate($expectedType, $credentials);

        if ($this->policyResolver->requiresSetup($user)) {
            $this->storePending($guard, $user->id, 'setup');

            return redirect()->route("{$guard}.two-factor.setup");
        }

        if ($this->policyResolver->requiresChallenge($user)) {
            $this->storePending($guard, $user->id, 'challenge');

            return redirect()->route("{$guard}.two-factor.challenge");
        }

        $this->authenticator->login($guard, $user);

        return $this->redirectAfterAuthentication($guard, $user);
    }

    public function redirectAfterAuthentication(string $guard, User $user): RedirectResponse
    {
        if ($user->must_change_password) {
            return redirect()->route("{$guard}.password.edit");
        }

        return GuardAwareRedirect::intended($guard, route("{$guard}.dashboard"));
    }

    public function pendingUser(string $guard): ?User
    {
        $userId = Session::get($this->pendingKey($guard, 'user_id'));

        if (! $userId) {
            return null;
        }

        return User::query()
            ->with(['businessPartner', 'customer.managingBp'])
            ->find($userId);
    }

    public function pendingPurpose(string $guard): ?string
    {
        return Session::get($this->pendingKey($guard, 'purpose'));
    }

    public function completePendingLogin(string $guard, User $user): void
    {
        $this->clearPending($guard);
        $this->authenticator->login($guard, $user);
    }

    public function clearPending(string $guard): void
    {
        Session::forget([
            $this->pendingKey($guard, 'user_id'),
            $this->pendingKey($guard, 'purpose'),
            $this->pendingKey($guard, 'setup_secret'),
        ]);
    }

    public function storeSetupSecret(string $guard, string $secret): void
    {
        Session::put($this->pendingKey($guard, 'setup_secret'), $secret);
    }

    public function setupSecret(string $guard): ?string
    {
        return Session::get($this->pendingKey($guard, 'setup_secret'));
    }

    private function storePending(string $guard, int $userId, string $purpose): void
    {
        Session::put($this->pendingKey($guard, 'user_id'), $userId);
        Session::put($this->pendingKey($guard, 'purpose'), $purpose);
    }

    private function pendingKey(string $guard, string $suffix): string
    {
        return "auth.{$guard}.pending.{$suffix}";
    }
}
