<?php

namespace App\Domain\Invoices\Exceptions;

use DomainException;

class InvoicePaymentReversalInvalid extends DomainException
{
    public static function create(): self
    {
        return new self('Platbu nelze reverzovat v požadovaném rozsahu.');
    }
}
