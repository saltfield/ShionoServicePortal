<?php

namespace App\Domains\Support\Enums;

enum InquiryMessageType: string
{
    case User = 'user';
    case StatusChange = 'status_change';

    public function label(): string
    {
        return match ($this) {
            self::User => 'メッセージ',
            self::StatusChange => 'ステータス変更',
        };
    }
}
