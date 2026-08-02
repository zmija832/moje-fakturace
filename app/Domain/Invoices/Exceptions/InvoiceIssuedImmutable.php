<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceIssuedImmutable extends RuntimeException
{
    public static function mutationDenied(): self
    {
        return new self('Vystavená faktura je neměnná.');
    }
}
