<?php

namespace App\Domains\Auth\Support;

final class IdentifierNormalizer
{
    public static function normalize(string $value): string
    {
        return strtoupper(trim($value));
    }
}
