<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Concerns\HandlesForcedPasswordChange;
use App\Http\Controllers\Controller;

class PasswordController extends Controller
{
    use HandlesForcedPasswordChange;

    protected function guardName(): string
    {
        return 'admin';
    }
}
