<?php

namespace App\Domain\Invoices\Exceptions;

use DomainException;

class InvoicePaymentIdempotencyConflict extends DomainException
{
    public static function create(): self
    {
        return new self('Correlation UUID již patří jiné platební operaci.');
    }
}
