<?php

namespace App\Domains\Support\Enums;

enum AnnouncementTargetType: string
{
    case All = 'all';
    case Bp = 'bp';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::All => '全体',
            self::Bp => 'BP',
            self::Customer => 'カスタマー',
        };
    }
}
