<?php

namespace App\Domain\Invoices;

use Illuminate\Validation\ValidationException;

final class InvoiceVariableSymbol
{
    public static function fromDocumentNumber(string $documentNumber): string
    {
        $digits = preg_replace('/\D+/', '', $documentNumber) ?? '';

        if ($digits === '' || strlen($digits) > 10) {
            throw ValidationException::withMessages([
                'variable_symbol' => 'Z čísla faktury nelze bezpečně vytvořit variabilní symbol.',
            ]);
        }

        return $digits;
    }
}
