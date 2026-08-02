<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceNotDraft extends RuntimeException
{
    public static function forIssue(): self
    {
        return new self('Vystavit lze pouze návrh faktury.');
    }

    public static function forEdit(): self
    {
        return new self('Upravovat lze pouze návrh faktury.');
    }
}
