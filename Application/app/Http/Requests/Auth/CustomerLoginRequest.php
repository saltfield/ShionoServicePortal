<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CustomerLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login_id' => ['required', 'string', 'max:64'],
            'cn' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string'],
        ];
    }
}
