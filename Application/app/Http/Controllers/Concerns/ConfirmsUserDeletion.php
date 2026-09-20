<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ConfirmsUserDeletion
{
    protected function issueUserDeleteConfirmationCode(User $user): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        session()->put($this->userDeleteConfirmationKey($user), $code);

        return $code;
    }

    protected function assertUserDeleteConfirmation(Request $request, User $user): void
    {
        $key = $this->userDeleteConfirmationKey($user);
        $expected = session()->pull($key);
        $input = (string) $request->input('confirmation_code', '');

        if ($expected === null || $input !== $expected) {
            $this->issueUserDeleteConfirmationCode($user);

            throw ValidationException::withMessages([
                'confirmation_code' => '確認コードが一致しません。もう一度お試しください。',
            ]);
        }
    }

    private function userDeleteConfirmationKey(User $user): string
    {
        return 'user_delete_confirm.'.$user->id;
    }
}
