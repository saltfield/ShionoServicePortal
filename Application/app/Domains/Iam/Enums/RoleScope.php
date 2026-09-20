<?php

namespace App\Domains\Iam\Enums;

enum RoleScope: string
{
    case System = 'system';
    case Bp = 'bp';
    case Customer = 'customer';
}
