<?php

namespace App\Domains\Support\Enums;

enum InquiryVisibility: string
{
    case Organization = 'organization';
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Organization => '自組織全体公開',
            self::Private => '自分のみ表示',
        };
    }
}
