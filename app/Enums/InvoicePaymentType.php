<?php

namespace App\Enums;

enum InvoicePaymentType: string
{
    case Payment = 'payment';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Platba',
            self::Reversal => 'Storno platby',
        };
    }
}
