<?php

namespace App\Enums;

enum VatRateDefaultContext: string
{
    case Sales = 'sales';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Výchozí sazba pro prodej',
        };
    }
}
