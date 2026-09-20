<?php

namespace App\Support;

final class TaxPrice
{
    /**
     * 税込金額（円未満は四捨五入）。
     */
    public static function inclusive(int|float|string $exclusive, int $taxRatePercent = 10): int
    {
        $base = (int) round((float) $exclusive);

        return (int) round($base * (100 + max(0, $taxRatePercent)) / 100);
    }

    public static function formatExclusive(int|float|string $exclusive): string
    {
        return number_format((int) round((float) $exclusive));
    }

    public static function formatInclusive(int|float|string $exclusive, int $taxRatePercent = 10): string
    {
        return number_format(self::inclusive($exclusive, $taxRatePercent));
    }
}
