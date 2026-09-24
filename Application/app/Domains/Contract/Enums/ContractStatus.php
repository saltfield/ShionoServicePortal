<?php

namespace App\Domains\Contract\Enums;

enum ContractStatus: string
{
    case Draft = 'draft';
    case PendingPriceApproval = 'pending_price_approval';
    case Approved = 'approved';
    case Activated = 'activated';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'オーダー作成中',
            self::PendingPriceApproval => '価格承認待ち',
            self::Approved => '承認済・手配中',
            self::Activated => 'サービス提供開始',
            self::Cancelled => '解約',
        };
    }
}
