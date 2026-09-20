<?php

namespace App\Domains\Contract\Support;

final class ReservedReplaceCodes
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'bp_name', 'bp_code', 'bp_addr', 'bp_tel', 'bp_mail',
            'c_name', 'c_code', 'c_addr', 'c_tel', 'c_mail',
            's_name', 's_addr', 's_tel',
            'sb_name', 'sb_addr', 'sb_tel',
            'ctr', 'icode', 'iname',
            'price', 'price_in', 'tax', 'rate',
            'part', 'part_in', 'part_tax',
        ];
    }

    public static function isReserved(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    public static function pattern(): string
    {
        return '/^[a-z][a-z0-9_]*$/';
    }
}
