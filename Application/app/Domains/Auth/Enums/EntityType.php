<?php

namespace App\Domains\Auth\Enums;

enum EntityType: string
{
    case Individual = 'individual';
    case Corporate = 'corporate';
}
