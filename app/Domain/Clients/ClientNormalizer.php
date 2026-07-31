<?php

namespace App\Domain\Clients;

use App\Enums\ClientType;

final class ClientNormalizer
{
    private const TEXT_FIELDS = [
        'display_name', 'company_name', 'first_name', 'last_name',
        'email', 'phone', 'website', 'contact_person',
        'street', 'house_number', 'orientation_number', 'city', 'postal_code',
        'delivery_name', 'delivery_street', 'delivery_house_number',
        'delivery_orientation_number', 'delivery_city', 'delivery_postal_code',
        'note',
    ];

    private const IDENTIFIER_FIELDS = ['registration_number', 'tax_id', 'vat_id'];

    private const DELIVERY_FIELDS = [
        'delivery_name', 'delivery_street', 'delivery_house_number',
        'delivery_orientation_number', 'delivery_city', 'delivery_postal_code',
        'delivery_country_code',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalize(array $attributes): array
    {
        foreach (self::TEXT_FIELDS as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = self::nullableTrim($attributes[$field]);
            }
        }

        foreach (self::IDENTIFIER_FIELDS as $field) {
            if (array_key_exists($field, $attributes)) {
                $value = self::nullableTrim($attributes[$field]);
                $attributes[$field] = $value === null
                    ? null
                    : preg_replace('/\s+/u', '', $value);
            }
        }

        if (isset($attributes['email'])) {
            $attributes['email'] = mb_strtolower($attributes['email']);
        }

        foreach (['country_code', 'delivery_country_code', 'default_currency'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $value = self::nullableTrim($attributes[$field]);
                $attributes[$field] = $value === null ? null : mb_strtoupper($value);
            }
        }

        if (array_key_exists('language', $attributes)) {
            $value = self::nullableTrim($attributes['language']);
            $attributes['language'] = $value === null ? null : mb_strtolower($value);
        }

        if (array_key_exists('type', $attributes)) {
            $attributes['type'] = self::nullableTrim($attributes['type']);
        }

        self::clearFieldsForType($attributes);
        self::clearEmptyDeliveryAddress($attributes);

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function clearFieldsForType(array &$attributes): void
    {
        if (($attributes['type'] ?? null) === ClientType::Company->value) {
            $attributes['first_name'] = null;
            $attributes['last_name'] = null;
        }

        if (($attributes['type'] ?? null) === ClientType::Person->value) {
            foreach (['company_name', 'registration_number', 'tax_id', 'vat_id', 'contact_person'] as $field) {
                $attributes[$field] = null;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function clearEmptyDeliveryAddress(array &$attributes): void
    {
        $hasDeliveryValue = false;

        foreach (self::DELIVERY_FIELDS as $field) {
            if (($attributes[$field] ?? null) !== null) {
                $hasDeliveryValue = true;
                break;
            }
        }

        if (! $hasDeliveryValue) {
            foreach (self::DELIVERY_FIELDS as $field) {
                $attributes[$field] = null;
            }
        }
    }

    private static function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function __construct() {}
}
