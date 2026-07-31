<?php

namespace App\Domain\BankAccounts;

final class BankAccountNormalizer
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalize(array $attributes): array
    {
        foreach (['domestic_prefix', 'domestic_account_number', 'bank_code'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = self::withoutWhitespace($attributes[$field]);
            }
        }

        if (array_key_exists('iban', $attributes)) {
            $attributes['iban'] = self::uppercaseWithoutWhitespace($attributes['iban']);
        }

        if (array_key_exists('bic', $attributes)) {
            $attributes['bic'] = self::uppercaseWithoutWhitespace($attributes['bic']);
        }

        if (array_key_exists('currency', $attributes) && is_string($attributes['currency'])) {
            $attributes['currency'] = mb_strtoupper(trim($attributes['currency']));
        }

        if (array_key_exists('name', $attributes) && is_string($attributes['name'])) {
            $attributes['name'] = trim($attributes['name']);
        }

        return $attributes;
    }

    private static function uppercaseWithoutWhitespace(mixed $value): mixed
    {
        $normalized = self::withoutWhitespace($value);

        return is_string($normalized) ? mb_strtoupper($normalized) : $normalized;
    }

    private static function withoutWhitespace(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = preg_replace('/\s+/u', '', $value);

        return $normalized === '' ? null : $normalized;
    }

    private function __construct() {}
}
