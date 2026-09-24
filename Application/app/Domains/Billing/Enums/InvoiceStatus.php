<?php

namespace App\Domains\Billing\Enums;

enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Paid = 'paid';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Issued => '発行済',
            self::Paid => '入金済',
            self::Withdrawn => '取下げ',
        };
    }
}
