<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceDraftVersionConflict extends RuntimeException
{
    public static function forVersions(int $expected, int $actual): self
    {
        return new self("Návrh faktury byl mezitím změněn (očekávaná verze {$expected}, aktuální {$actual}).");
    }
}
