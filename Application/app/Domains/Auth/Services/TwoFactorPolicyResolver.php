<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Enums\UserType;
use App\Models\User;

class TwoFactorPolicyResolver
{
    public function resolve(User $user): TwoFactorMode
    {
        return match ($user->user_type) {
            UserType::Admin => $user->two_factor_mode ?? TwoFactorMode::Optional,
            UserType::Bp => $user->businessPartner?->two_factor_mode ?? TwoFactorMode::Optional,
            UserType::Customer => $user->customer?->two_factor_mode ?? TwoFactorMode::Optional,
        };
    }

    public function requiresChallenge(User $user): bool
    {
        if ($user->two_factor_forced_disabled) {
            return false;
        }

        $mode = $this->resolve($user);

        if ($mode === TwoFactorMode::Disabled) {
            return false;
        }

        return $user->two_factor_confirmed_at !== null && filled($user->two_factor_secret);
    }

    public function requiresSetup(User $user): bool
    {
        if ($user->two_factor_forced_disabled) {
            return false;
        }

        $mode = $this->resolve($user);

        if ($mode !== TwoFactorMode::Forced) {
            return false;
        }

        return $user->two_factor_confirmed_at === null || blank($user->two_factor_secret);
    }

    public function canSelfDisable(User $user): bool
    {
        return $this->resolve($user) === TwoFactorMode::Optional;
    }

    public function canSelfEnable(User $user): bool
    {
        $mode = $this->resolve($user);

        return $mode === TwoFactorMode::Optional || $mode === TwoFactorMode::Forced;
    }
}
