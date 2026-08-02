<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceDraftIdempotencyConflict extends RuntimeException
{
    public static function reusedCorrelation(): self
    {
        return new self('Correlation UUID již bylo použito pro jinou fakturu.');
    }
}
