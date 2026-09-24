<?php

namespace App\Domains\Billing\Enums;

enum BillingBatchDayMode: string
{
    case MonthEnd = 'month_end';
    case DayOfMonth = 'day_of_month';

    public function label(): string
    {
        return match ($this) {
            self::MonthEnd => '月末',
            self::DayOfMonth => '毎月N日',
        };
    }
}
