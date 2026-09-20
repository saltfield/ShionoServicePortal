<?php

namespace App\Domains\Auth\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordChangeService
{
    public function change(User $user, string $password, ?string $currentPassword = null): void
    {
        if (! $user->must_change_password) {
            if ($currentPassword === null || $currentPassword === '' || ! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => '現在のパスワードが正しくありません。',
                ]);
            }
        }

        if (Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => '新しいパスワードは現在のパスワードと別にしてください。',
            ]);
        }

        $user->forceFill([
            'password' => $password,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();
    }
}
