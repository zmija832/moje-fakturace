<?php

namespace App\Enums;

enum InvoicePaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overpaid = 'overpaid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Neuhrazená',
            self::PartiallyPaid => 'Částečně uhrazená',
            self::Paid => 'Uhrazená',
            self::Overpaid => 'Přeplacená',
        };
    }
}
