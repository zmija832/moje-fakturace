<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceNotReadyForIssue extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('Faktura není připravena k vystavení: '.$reason);
    }
}
