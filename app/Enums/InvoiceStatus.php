<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Návrh',
        };
    }
}
