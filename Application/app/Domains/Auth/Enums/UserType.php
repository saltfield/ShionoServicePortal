<?php

namespace App\Domains\Auth\Enums;

enum UserType: string
{
    case Admin = 'admin';
    case Bp = 'bp';
    case Customer = 'customer';
}
