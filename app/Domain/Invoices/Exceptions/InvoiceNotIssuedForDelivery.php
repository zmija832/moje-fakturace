<?php

namespace App\Domain\Invoices\Exceptions;

use DomainException;

class InvoiceNotIssuedForDelivery extends DomainException
{
    public static function create(): self
    {
        return new self('Dokumenty a odeslání jsou dostupné pouze pro vystavenou fakturu.');
    }
}
