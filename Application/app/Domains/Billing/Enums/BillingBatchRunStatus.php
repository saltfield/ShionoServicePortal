<?php

namespace App\Domains\Billing\Enums;

enum BillingBatchRunStatus: string
{
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Success => '成功',
            self::Partial => '一部失敗',
            self::Failed => '失敗',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Success => 'text-bg-success',
            self::Partial => 'text-bg-warning',
            self::Failed => 'text-bg-danger',
        };
    }
}
