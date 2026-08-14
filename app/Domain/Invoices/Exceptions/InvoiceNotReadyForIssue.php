<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceNotReadyForIssue extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Faktura není připravena k vystavení: '.$reason);
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
