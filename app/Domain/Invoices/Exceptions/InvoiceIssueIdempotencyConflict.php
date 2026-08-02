<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceIssueIdempotencyConflict extends RuntimeException
{
    public static function reusedCorrelation(): self
    {
        return new self('Idempotency klíč vystavení již patří jiné faktuře nebo operaci.');
    }
}
