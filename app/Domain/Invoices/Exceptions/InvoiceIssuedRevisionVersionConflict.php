<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceIssuedRevisionVersionConflict extends RuntimeException
{
    public static function forVersions(int $expected, int $actual): self
    {
        return new self("Vystavená faktura byla mezitím změněna (očekávaná verze {$expected}, aktuální {$actual}).");
    }
}
