<?php

namespace App\Domain\Invoices;

use InvalidArgumentException;

final class InvoiceDecimal
{
    public const SCALE = 4;

    public const MONEY_INTEGER_DIGITS = 15;

    public const QUANTITY_INTEGER_DIGITS = 14;

    public static function quantity(mixed $input): string
    {
        $value = self::input($input, self::SCALE, self::QUANTITY_INTEGER_DIGITS, false);

        if (self::compare($value, '0') <= 0) {
            throw new InvalidArgumentException('Množství musí být větší než nula.');
        }

        return $value;
    }

    public static function money(mixed $input): string
    {
        return self::input($input, self::SCALE, self::MONEY_INTEGER_DIGITS, false);
    }

    public static function percentage(mixed $input): string
    {
        $value = self::input($input, self::SCALE, 3, false);

        if (self::compare($value, '100') > 0) {
            throw new InvalidArgumentException('Procentní hodnota nesmí být vyšší než 100.');
        }

        return $value;
    }

    public static function normalize(mixed $input, int $scale = self::SCALE, bool $allowNegative = true): string
    {
        [$sign, $digits, $sourceScale] = self::parse($input);

        if (! $allowNegative && $sign < 0) {
            throw new InvalidArgumentException('Hodnota nesmí být záporná.');
        }

        return self::render($sign, $digits, $sourceScale, $scale);
    }

    public static function add(mixed $left, mixed $right, int $scale = self::SCALE): string
    {
        [$leftSign, $leftDigits, $leftScale] = self::parse($left);
        [$rightSign, $rightDigits, $rightScale] = self::parse($right);
        $workingScale = max($leftScale, $rightScale, $scale);
        $leftDigits .= str_repeat('0', $workingScale - $leftScale);
        $rightDigits .= str_repeat('0', $workingScale - $rightScale);
        [$sign, $digits] = self::signedAdd($leftSign, $leftDigits, $rightSign, $rightDigits);

        return self::render($sign, $digits, $workingScale, $scale);
    }

    public static function subtract(mixed $left, mixed $right, int $scale = self::SCALE): string
    {
        [$rightSign, $rightDigits, $rightScale] = self::parse($right);

        return self::add($left, self::compose(-$rightSign, $rightDigits, $rightScale), $scale);
    }

    public static function multiply(mixed $left, mixed $right, int $scale = self::SCALE): string
    {
        [$leftSign, $leftDigits, $leftScale] = self::parse($left);
        [$rightSign, $rightDigits, $rightScale] = self::parse($right);

        return self::render(
            $leftSign * $rightSign,
            self::multiplyUnsigned($leftDigits, $rightDigits),
            $leftScale + $rightScale,
            $scale,
        );
    }

    public static function divide(mixed $dividend, mixed $divisor, int $scale = self::SCALE): string
    {
        [$dividendSign, $dividendDigits, $dividendScale] = self::parse($dividend);
        [$divisorSign, $divisorDigits, $divisorScale] = self::parse($divisor);

        if ($divisorSign === 0) {
            throw new InvalidArgumentException('Nelze dělit nulou.');
        }

        if ($dividendSign === 0) {
            return self::zero($scale);
        }

        $calculationScale = $scale + 1;
        $power = $divisorScale + $calculationScale - $dividendScale;
        $numerator = $dividendDigits;
        $denominator = $divisorDigits;

        if ($power >= 0) {
            $numerator .= str_repeat('0', $power);
        } else {
            $denominator .= str_repeat('0', -$power);
        }

        $quotient = self::divideUnsigned($numerator, $denominator);

        return self::render($dividendSign * $divisorSign, $quotient, $calculationScale, $scale);
    }

    public static function compare(mixed $left, mixed $right): int
    {
        [$leftSign, $leftDigits, $leftScale] = self::parse($left);
        [$rightSign, $rightDigits, $rightScale] = self::parse($right);

        if ($leftSign !== $rightSign) {
            return $leftSign <=> $rightSign;
        }

        if ($leftSign === 0) {
            return 0;
        }

        $scale = max($leftScale, $rightScale);
        $comparison = self::compareUnsigned(
            $leftDigits.str_repeat('0', $scale - $leftScale),
            $rightDigits.str_repeat('0', $scale - $rightScale),
        );

        return $leftSign * $comparison;
    }

    public static function absolute(mixed $value, int $scale = self::SCALE): string
    {
        [, $digits, $sourceScale] = self::parse($value);

        return self::render(1, $digits, $sourceScale, $scale);
    }

    public static function round(mixed $value, int $scale): string
    {
        [$sign, $digits, $sourceScale] = self::parse($value);

        return self::render($sign, $digits, $sourceScale, $scale);
    }

    public static function database(mixed $value, int $maxIntegerDigits = self::MONEY_INTEGER_DIGITS): string
    {
        $normalized = self::normalize($value, self::SCALE);
        self::assertFits($normalized, $maxIntegerDigits);

        return $normalized;
    }

    public static function format(mixed $value, int $scale = 2, string $decimalSeparator = ','): string
    {
        return str_replace('.', $decimalSeparator, self::normalize($value, $scale));
    }

    public static function formatMoney(mixed $value, string $currency): string
    {
        return self::formatAmount($value).' '.strtoupper(trim($currency));
    }

    public static function formatAmount(mixed $value): string
    {
        return self::formatDecimal($value, 2, ',', true, 2);
    }

    public static function formatQuantity(mixed $value): string
    {
        return self::formatDecimal($value, self::SCALE);
    }

    public static function formatInput(mixed $value, int $maxScale = self::SCALE): string
    {
        return self::formatDecimal($value, $maxScale, '.', false);
    }

    public static function formatDecimal(
        mixed $value,
        int $maxScale = self::SCALE,
        string $decimalSeparator = ',',
        bool $groupThousands = true,
        int $fractionDigitsWhenNonZero = 0,
    ): string {
        $normalized = self::normalize($value, $maxScale);
        $negative = str_starts_with($normalized, '-');
        $absolute = ltrim($normalized, '-');
        [$integer, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        if ($fraction !== '' && $fractionDigitsWhenNonZero > 0) {
            $fraction = str_pad($fraction, $fractionDigitsWhenNonZero, '0');
        }

        if ($groupThousands) {
            $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $integer) ?? $integer;
        }

        return ($negative ? '-' : '').$integer.($fraction === '' ? '' : $decimalSeparator.$fraction);
    }

    public static function assertFits(mixed $value, int $maxIntegerDigits = self::MONEY_INTEGER_DIGITS): void
    {
        $normalized = self::normalize($value, self::SCALE);
        $integer = ltrim(explode('.', ltrim($normalized, '-'), 2)[0], '0') ?: '0';

        if (strlen($integer) > $maxIntegerDigits) {
            throw new InvalidArgumentException('Hodnota překračuje podporovaný databázový rozsah.');
        }
    }

    private static function input(mixed $input, int $scale, int $maxIntegerDigits, bool $allowNegative): string
    {
        [$sign, $digits, $sourceScale, $integerDigits] = self::parse($input, true);

        if (! $allowNegative && $sign < 0) {
            throw new InvalidArgumentException('Hodnota nesmí být záporná.');
        }

        if ($sourceScale > $scale) {
            throw new InvalidArgumentException("Hodnota smí mít nejvýše {$scale} desetinná místa.");
        }

        if ($integerDigits > $maxIntegerDigits) {
            throw new InvalidArgumentException('Hodnota je příliš vysoká.');
        }

        return self::render($sign, $digits, $sourceScale, $scale);
    }

    /** @return array{int, string, int, int} */
    private static function parse(mixed $input, bool $withIntegerDigits = false): array
    {
        if (! is_string($input) && ! is_int($input)) {
            throw new InvalidArgumentException('Desetinná hodnota musí být předána jako string nebo integer.');
        }

        $value = str_replace(',', '.', trim((string) $input));

        if (! preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Hodnota musí být běžné desetinné číslo bez vědeckého zápisu.');
        }

        $integer = ltrim($matches[2], '0') ?: '0';
        $fraction = $matches[3] ?? '';
        $digits = ltrim($integer.$fraction, '0') ?: '0';
        $sign = $digits === '0' ? 0 : (($matches[1] ?? '') === '-' ? -1 : 1);

        return [$sign, $digits, strlen($fraction), strlen($integer)];
    }

    private static function render(int $sign, string $digits, int $sourceScale, int $targetScale): string
    {
        if ($targetScale < 0) {
            throw new InvalidArgumentException('Přesnost nesmí být záporná.');
        }

        $digits = self::trimUnsigned($digits);

        if ($sourceScale > $targetScale) {
            $removed = substr($digits, -($sourceScale - $targetScale));
            $digits = substr($digits, 0, max(0, strlen($digits) - ($sourceScale - $targetScale))) ?: '0';

            if ($removed !== '' && $removed[0] >= '5') {
                $digits = self::addUnsigned($digits, '1');
            }
        } elseif ($sourceScale < $targetScale) {
            $digits .= str_repeat('0', $targetScale - $sourceScale);
        }

        $digits = str_pad($digits, $targetScale + 1, '0', STR_PAD_LEFT);
        $integer = self::trimUnsigned($targetScale === 0 ? $digits : substr($digits, 0, -$targetScale));
        $fraction = $targetScale === 0 ? '' : substr($digits, -$targetScale);
        $isZero = $integer === '0' && trim($fraction, '0') === '';

        return ($sign < 0 && ! $isZero ? '-' : '').$integer.($targetScale > 0 ? '.'.$fraction : '');
    }

    private static function compose(int $sign, string $digits, int $scale): string
    {
        return self::render($sign, $digits, $scale, $scale);
    }

    /** @return array{int, string} */
    private static function signedAdd(int $leftSign, string $left, int $rightSign, string $right): array
    {
        if ($leftSign === 0) {
            return [$rightSign, self::trimUnsigned($right)];
        }

        if ($rightSign === 0) {
            return [$leftSign, self::trimUnsigned($left)];
        }

        if ($leftSign === $rightSign) {
            return [$leftSign, self::addUnsigned($left, $right)];
        }

        $comparison = self::compareUnsigned($left, $right);

        if ($comparison === 0) {
            return [0, '0'];
        }

        return $comparison > 0
            ? [$leftSign, self::subtractUnsigned($left, $right)]
            : [$rightSign, self::subtractUnsigned($right, $left)];
    }

    private static function addUnsigned(string $left, string $right): string
    {
        $length = max(strlen($left), strlen($right));
        $left = str_pad($left, $length, '0', STR_PAD_LEFT);
        $right = str_pad($right, $length, '0', STR_PAD_LEFT);
        $carry = 0;
        $result = '';

        for ($index = $length - 1; $index >= 0; $index--) {
            $sum = (int) $left[$index] + (int) $right[$index] + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ($carry > 0 ? (string) $carry : '').$result;
    }

    private static function subtractUnsigned(string $left, string $right): string
    {
        $length = max(strlen($left), strlen($right));
        $left = str_pad($left, $length, '0', STR_PAD_LEFT);
        $right = str_pad($right, $length, '0', STR_PAD_LEFT);
        $borrow = 0;
        $result = '';

        for ($index = $length - 1; $index >= 0; $index--) {
            $digit = (int) $left[$index] - (int) $right[$index] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = ($digit < 0 ? $digit + 10 : $digit).$result;
        }

        return self::trimUnsigned($result);
    }

    private static function multiplyUnsigned(string $left, string $right): string
    {
        $left = self::trimUnsigned($left);
        $right = self::trimUnsigned($right);

        if ($left === '0' || $right === '0') {
            return '0';
        }

        $result = array_fill(0, strlen($left) + strlen($right), 0);

        for ($leftIndex = strlen($left) - 1; $leftIndex >= 0; $leftIndex--) {
            for ($rightIndex = strlen($right) - 1; $rightIndex >= 0; $rightIndex--) {
                $position = $leftIndex + $rightIndex + 1;
                $product = ((int) $left[$leftIndex] * (int) $right[$rightIndex]) + $result[$position];
                $result[$position] = $product % 10;
                $result[$position - 1] += intdiv($product, 10);
            }
        }

        return self::trimUnsigned(implode('', $result));
    }

    private static function divideUnsigned(string $dividend, string $divisor): string
    {
        $dividend = self::trimUnsigned($dividend);
        $divisor = self::trimUnsigned($divisor);
        $remainder = '0';
        $quotient = '';

        foreach (str_split($dividend) as $digit) {
            $remainder = self::trimUnsigned($remainder.$digit);
            $quotientDigit = 0;

            while (self::compareUnsigned($remainder, $divisor) >= 0) {
                $remainder = self::subtractUnsigned($remainder, $divisor);
                $quotientDigit++;
            }

            $quotient .= (string) $quotientDigit;
        }

        return self::trimUnsigned($quotient);
    }

    private static function compareUnsigned(string $left, string $right): int
    {
        $left = self::trimUnsigned($left);
        $right = self::trimUnsigned($right);

        return strlen($left) === strlen($right)
            ? strcmp($left, $right) <=> 0
            : strlen($left) <=> strlen($right);
    }

    private static function trimUnsigned(string $digits): string
    {
        return ltrim($digits, '0') ?: '0';
    }

    private static function zero(int $scale): string
    {
        return $scale === 0 ? '0' : '0.'.str_repeat('0', $scale);
    }
}
