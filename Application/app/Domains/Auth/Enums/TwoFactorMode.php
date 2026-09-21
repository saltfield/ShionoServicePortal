<?php

namespace App\Domains\Auth\Enums;

enum TwoFactorMode: string
{
    case Forced = 'forced';
    case Optional = 'optional';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Forced => '強制',
            self::Optional => '任意',
            self::Disabled => '無効',
        };
    }
}
