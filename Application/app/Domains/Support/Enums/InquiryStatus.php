<?php

namespace App\Domains\Support\Enums;

enum InquiryStatus: string
{
    case Submitted = 'submitted';
    case Withdrawn = 'withdrawn';
    case InProgress = 'in_progress';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => '起票',
            self::Withdrawn => '取下',
            self::InProgress => '受領対応中',
            self::Closed => 'クローズ',
        };
    }
}
