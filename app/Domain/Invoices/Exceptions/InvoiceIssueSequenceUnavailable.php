<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceIssueSequenceUnavailable extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('Pro vystavení není dostupná platná číselná řada vydaných faktur.');
    }
}
