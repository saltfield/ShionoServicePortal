<?php

namespace App\Domains\Support\Enums;

enum InquiryStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => '未対応',
            self::InProgress => '対応中',
            self::Closed => 'クローズ',
        };
    }
}
