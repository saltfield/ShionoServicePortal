<?php

namespace App\Http\Controllers\Customer\Auth;

use App\Domains\Auth\Enums\UserType;
use App\Http\Controllers\Concerns\HandlesTwoFactorAuth;
use App\Http\Controllers\Controller;

class TwoFactorController extends Controller
{
    use HandlesTwoFactorAuth;

    protected function guardName(): string
    {
        return 'customer';
    }

    protected function userType(): UserType
    {
        return UserType::Customer;
    }
}
