<?php

namespace App\Enums;

enum InvoiceDiscountType: string
{
    case None = 'none';
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Bez slevy',
            self::Percentage => 'Procentní sleva',
            self::Fixed => 'Pevná sleva',
        };
    }
}
