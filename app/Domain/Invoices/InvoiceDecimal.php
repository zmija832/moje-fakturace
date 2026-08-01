<?php

namespace App\Domain\Invoices;

use InvalidArgumentException;

final class InvoiceDecimal
{
    public const SCALE = 4;

    public static function quantity(string|int $input): string
    {
        $value = self::normalize($input, 14);

        if ($value === '0.0000') {
            throw new InvalidArgumentException('Množství musí být větší než nula.');
        }

        return $value;
    }

    public static function money(string|int $input): string
    {
        return self::normalize($input, 15);
    }

    private static function normalize(string|int $input, int $maxIntegerDigits): string
    {
        $value = str_replace(',', '.', trim((string) $input));

        if (! preg_match('/^[0-9]+(?:\.[0-9]{1,4})?$/', $value)) {
            throw new InvalidArgumentException('Hodnota musí být nezáporné desetinné číslo s nejvýše čtyřmi desetinnými místy.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';

        if (strlen($integer) > $maxIntegerDigits) {
            throw new InvalidArgumentException('Hodnota je příliš vysoká.');
        }

        return $integer.'.'.str_pad($decimal, self::SCALE, '0');
    }
}
