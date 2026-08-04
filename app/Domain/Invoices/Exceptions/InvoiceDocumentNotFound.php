<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceDocumentNotFound extends RuntimeException
{
    public static function create(): self
    {
        return new self('PDF dokument není dostupný. Správce jej může vygenerovat znovu.');
    }
}
