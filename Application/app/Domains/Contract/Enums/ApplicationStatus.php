<?php

namespace App\Domains\Contract\Enums;

enum ApplicationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '申請中',
            self::Approved => '承認',
            self::Rejected => '却下',
        };
    }
}
