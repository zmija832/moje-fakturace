<?php

namespace App\Domain\Vat\Exceptions;

use RuntimeException;

class VatRateUnavailable extends RuntimeException
{
    public static function forDate(): self
    {
        return new self('Sazbu DPH nelze pro zadané datum použít.');
    }

    public static function missingDefault(): self
    {
        return new self('Pro zadaný kontext není nastavena použitelná výchozí sazba DPH.');
    }
}
