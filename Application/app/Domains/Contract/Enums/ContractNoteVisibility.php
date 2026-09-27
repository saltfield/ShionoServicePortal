<?php

namespace App\Domains\Contract\Enums;

enum ContractNoteVisibility: string
{
    case Shared = 'shared';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::Shared => '共有備考',
            self::Organization => '組織内備考',
        };
    }
}
