<?php

namespace App\Support;

final class ContactFieldRules
{
    /**
     * @return list<string|\Illuminate\Contracts\Validation\ValidationRule>
     */
    public static function postalCode(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:8',
            'regex:/^\d{3}-?\d{4}$/',
        ];
    }

    /**
     * @return list<string|\Illuminate\Contracts\Validation\ValidationRule>
     */
    public static function phone(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:20',
            'regex:/^[0-9\-]+$/',
        ];
    }

    public static function normalizePostalCode(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) !== 7) {
            return $value;
        }

        return substr($digits, 0, 3).'-'.substr($digits, 3);
    }

    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return preg_replace('/[^0-9\-]/', '', $value) ?: null;
    }
}
