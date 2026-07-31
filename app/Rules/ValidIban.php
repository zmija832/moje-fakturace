<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidIban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::passesChecksum($value)) {
            $fail('Pole :attribute musí obsahovat platný IBAN.');
        }
    }

    public static function passesChecksum(string $iban): bool
    {
        if (
            strlen($iban) < 15
            || strlen($iban) > 34
            || preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban) !== 1
        ) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;

        foreach (str_split($rearranged) as $character) {
            $digits = ctype_alpha($character)
                ? (string) (ord($character) - 55)
                : $character;

            foreach (str_split($digits) as $digit) {
                $remainder = (($remainder * 10) + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }
}
