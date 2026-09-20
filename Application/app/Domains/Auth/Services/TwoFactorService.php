<?php

namespace App\Domains\Auth\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly TwoFactorPolicyResolver $policyResolver,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function qrCodeUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->login_id,
            $secret
        );
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code);
    }

    public function confirmSetup(User $user, string $secret, string $code): void
    {
        if (! $this->policyResolver->canSelfEnable($user) && ! $this->policyResolver->requiresSetup($user)) {
            throw ValidationException::withMessages([
                'code' => '現在の2FAポリシーでは有効化できません。',
            ]);
        }

        if (! $this->verify($secret, $code)) {
            throw ValidationException::withMessages([
                'code' => '認証コードが正しくありません。',
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function disable(User $user): void
    {
        if (! $this->policyResolver->canSelfDisable($user)) {
            throw ValidationException::withMessages([
                'two_factor' => '現在の2FAポリシーでは無効化できません。',
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function plainSecret(User $user): ?string
    {
        return $user->two_factor_secret;
    }
}
