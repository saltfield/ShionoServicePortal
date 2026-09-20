<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class PasswordChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->user($this->routeGuard());
        $rules = [
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];

        if ($user && ! $user->must_change_password) {
            $rules['current_password'] = ['required', 'string'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'current_password.required' => '現在のパスワードを入力してください。',
        ];
    }

    private function routeGuard(): string
    {
        if ($this->routeIs('admin.*')) {
            return 'admin';
        }
        if ($this->routeIs('bp.*')) {
            return 'bp';
        }

        return 'customer';
    }
}
