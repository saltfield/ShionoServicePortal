<?php

namespace App\Http\Controllers\Bp\Auth;

use App\Domains\Auth\Enums\UserType;
use App\Http\Controllers\Concerns\HandlesTwoFactorAuth;
use App\Http\Controllers\Controller;

class TwoFactorController extends Controller
{
    use HandlesTwoFactorAuth;

    protected function guardName(): string
    {
        return 'bp';
    }

    protected function userType(): UserType
    {
        return UserType::Bp;
    }
}
