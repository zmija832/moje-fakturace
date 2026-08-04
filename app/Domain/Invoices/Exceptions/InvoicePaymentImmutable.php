<?php

namespace App\Domain\Invoices\Exceptions;

use LogicException;

class InvoicePaymentImmutable extends LogicException
{
    public static function create(): self
    {
        return new self('Platební ledger je neměnný; oprava musí vzniknout reverzním záznamem.');
    }
}
