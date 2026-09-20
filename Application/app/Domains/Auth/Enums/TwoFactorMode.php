<?php

namespace App\Domains\Auth\Enums;

enum TwoFactorMode: string
{
    case Forced = 'forced';
    case Optional = 'optional';
    case Disabled = 'disabled';
}
