<?php

namespace App\Domains\Support\Enums;

enum AnnouncementTargetType: string
{
    case All = 'all';
    case AllBp = 'all_bp';
    case AllCustomer = 'all_customer';
    case Bp = 'bp';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::All => '全BPと全カスタマー',
            self::AllBp => '全BPのみ',
            self::AllCustomer => '全カスタマーのみ',
            self::Bp => 'BP',
            self::Customer => 'カスタマー',
        };
    }

    public function isBroadcast(): bool
    {
        return in_array($this, [self::All, self::AllBp, self::AllCustomer], true);
    }
}
