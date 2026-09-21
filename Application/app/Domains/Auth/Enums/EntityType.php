<?php

namespace App\Domains\Auth\Enums;

enum EntityType: string
{
    case Individual = 'individual';
    case Corporate = 'corporate';

    public function label(): string
    {
        return match ($this) {
            self::Individual => '個人',
            self::Corporate => '法人',
        };
    }
}
