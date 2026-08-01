<?php

namespace App\Domain\Vat;

use InvalidArgumentException;

final readonly class VatPercentage
{
    public const SCALE = 4;

    private function __construct(public string $value) {}

    public static function from(string|int $input): self
    {
        $value = str_replace(',', '.', trim((string) $input));

        if (! preg_match('/^[0-9]+(?:\.[0-9]{1,4})?$/', $value)) {
            throw new InvalidArgumentException('Sazba musí být desetinné číslo s nejvýše čtyřmi desetinnými místy.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';

        if (strlen($integer) > 3 || (strlen($integer) === 3 && strcmp($integer, '100') > 0)) {
            throw new InvalidArgumentException('Sazba nesmí být vyšší než 100 %.');
        }

        if ($integer === '100' && trim($decimal, '0') !== '') {
            throw new InvalidArgumentException('Sazba nesmí být vyšší než 100 %.');
        }

        return new self($integer.'.'.str_pad($decimal, self::SCALE, '0'));
    }

    public function isZero(): bool
    {
        return $this->value === '0.0000';
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
