<?php

namespace App\Domain\Invoices\Exceptions;

use DomainException;

class InvoiceDeliveryIdempotencyConflict extends DomainException
{
    public static function create(): self
    {
        return new self('Identifikátor operace již patří jinému požadavku.');
    }
}
