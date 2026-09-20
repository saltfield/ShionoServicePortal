<?php

namespace App\Domains\Catalog\Enums;

enum BillingType: string
{
    case Initial = 'initial';
    case Running = 'running';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'イニシャル',
            self::Running => 'ランニング',
        };
    }
}
