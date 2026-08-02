<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceIssueVersionConflict extends RuntimeException
{
    public static function forVersions(int $expected, int $actual): self
    {
        return new self('Verze faktury se změnila (očekávána '.$expected.', aktuální '.$actual.').');
    }
}
