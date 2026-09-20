<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Domains\Auth\Enums\UserType;
use App\Http\Controllers\Concerns\HandlesTwoFactorAuth;
use App\Http\Controllers\Controller;

class TwoFactorController extends Controller
{
    use HandlesTwoFactorAuth;

    protected function guardName(): string
    {
        return 'admin';
    }

    protected function userType(): UserType
    {
        return UserType::Admin;
    }
}
