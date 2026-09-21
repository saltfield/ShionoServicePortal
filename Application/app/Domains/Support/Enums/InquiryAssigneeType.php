<?php

namespace App\Domains\Support\Enums;

enum InquiryAssigneeType: string
{
    case Admin = 'admin';
    case Bp = 'bp';

    public function label(): string
    {
        return match ($this) {
            self::Admin => '管理者',
            self::Bp => 'BP',
        };
    }
}
