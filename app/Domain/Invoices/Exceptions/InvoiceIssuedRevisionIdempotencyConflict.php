<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceIssuedRevisionIdempotencyConflict extends RuntimeException
{
    public static function reusedCorrelation(): self
    {
        return new self('Identifikátor admin úpravy byl použit pro jinou fakturu.');
    }
}
