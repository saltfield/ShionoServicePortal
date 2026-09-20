<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Iam\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CredentialAuthenticator
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{login_id: string, password: string, bpn?: string|null, cn?: string|null}  $credentials
     */
    public function validate(UserType $expectedType, array $credentials): User
    {
        $loginId = IdentifierNormalizer::normalize($credentials['login_id'] ?? '');

        /** @var User|null $user */
        $user = User::query()
            ->with(['businessPartner', 'customer.managingBp'])
            ->where('login_id', $loginId)
            ->first();

        if ($user === null || $user->user_type !== $expectedType) {
            $this->failLogin($expectedType, $loginId, 'invalid_credentials');
        }

        if (! $user->is_active) {
            $this->failLogin($expectedType, $loginId, 'inactive', $user);
        }

        if (! Hash::check($credentials['password'] ?? '', $user->password)) {
            $this->failLogin($expectedType, $loginId, 'invalid_password', $user);
        }

        if ($expectedType === UserType::Bp) {
            $bpn = IdentifierNormalizer::normalize($credentials['bpn'] ?? '');
            if ($user->businessPartner === null || $user->businessPartner->code !== $bpn) {
                $this->failLogin($expectedType, $loginId, 'bpn_mismatch', $user);
            }
        }

        if ($expectedType === UserType::Customer) {
            $cn = IdentifierNormalizer::normalize($credentials['cn'] ?? '');
            if ($user->customer === null || $user->customer->code !== $cn) {
                $this->failLogin($expectedType, $loginId, 'cn_mismatch', $user);
            }
        }

        return $user;
    }

    /**
     * @param  array{login_id: string, password: string, bpn?: string|null, cn?: string|null}  $credentials
     */
    public function attempt(UserType $expectedType, string $guard, array $credentials): User
    {
        $user = $this->validate($expectedType, $credentials);
        $this->login($guard, $user);

        return $user;
    }

    public function login(string $guard, User $user): void
    {
        Auth::guard($guard)->login($user, false);
        $user->forceFill(['last_login_at' => now()])->save();

        $this->auditLogger->log(
            category: 'login',
            action: "login.{$guard}",
            result: 'success',
            actor: $user,
            meta: ['user_type' => $user->user_type->value],
        );
    }

    public function logout(string $guard): void
    {
        Auth::guard($guard)->logout();
    }

    private function failLogin(UserType $expectedType, string $loginId, string $reason, ?User $user = null): never
    {
        $this->auditLogger->log(
            category: 'login',
            action: 'login.'.$expectedType->value,
            result: 'failure',
            actor: $user,
            targetUser: $user,
            meta: [
                'login_id' => $loginId,
                'reason' => $reason,
            ],
        );

        $this->fail();
    }

    private function fail(): never
    {
        throw ValidationException::withMessages([
            'login_id' => __('認証に失敗しました。入力内容をご確認ください。'),
        ]);
    }
}
