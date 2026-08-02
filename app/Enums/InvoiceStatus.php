<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Návrh',
            self::Issued => 'Vystavená',
        };
    }
}
