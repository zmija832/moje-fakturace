<?php

namespace App\Domain\Invoices\Exceptions;

use DomainException;

class InvoicePaymentNotAllowed extends DomainException
{
    public static function create(): self
    {
        return new self('Platbu lze evidovat pouze k vystavené faktuře.');
    }
}
