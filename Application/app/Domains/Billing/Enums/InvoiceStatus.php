<?php

namespace App\Domains\Billing\Enums;

enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Issued => '発行済',
            self::Paid => '入金済',
            self::Cancelled => '取消',
        };
    }
}
