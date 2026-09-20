<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class BpLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login_id' => ['required', 'string', 'max:64'],
            'bpn' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string'],
        ];
    }
}
