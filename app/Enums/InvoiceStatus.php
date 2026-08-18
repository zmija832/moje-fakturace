<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Návrh',
            self::Issued => 'Vystavená',
            self::Cancelled => 'Stornovaná',
        };
    }

    public function hasIssuedDocument(): bool
    {
        return $this === self::Issued || $this === self::Cancelled;
    }
}
