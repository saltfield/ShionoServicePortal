<?php

namespace App\Domains\Contract\Enums;

enum ApplicationType: string
{
    case PriceApproval = 'price_approval';
    case PriceChange = 'price_change';

    public function label(): string
    {
        return match ($this) {
            self::PriceApproval => '価格承認',
            self::PriceChange => '価格変更申請',
        };
    }
}
