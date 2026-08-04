<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoicePdfGenerationFailed extends RuntimeException
{
    public static function create(): self
    {
        return new self('PDF faktury se nepodařilo bezpečně vytvořit.');
    }
}
