<?php

namespace App\Domains\Billing\Enums;

enum BillingBatchRunTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';

    public function label(): string
    {
        return match ($this) {
            self::Manual => '手動',
            self::Scheduled => '自動',
        };
    }
}
