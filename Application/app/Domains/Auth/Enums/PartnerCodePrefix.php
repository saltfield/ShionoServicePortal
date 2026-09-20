<?php

namespace App\Domains\Auth\Enums;

enum PartnerCodePrefix: string
{
    case Bpn = 'BPN';
    case Cn = 'CN';
    case Item = 'ITEM';
    case Contract = 'CTR';
    case Invoice = 'INV';

    public static function tryFromNormalized(string $value): ?self
    {
        $normalized = strtoupper(trim($value));

        return self::tryFrom($normalized);
    }
}
