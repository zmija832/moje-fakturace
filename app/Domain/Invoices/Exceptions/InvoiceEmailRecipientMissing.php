<?php

namespace App\Domain\Invoices\Exceptions;

use DomainException;

class InvoiceEmailRecipientMissing extends DomainException
{
    public static function create(): self
    {
        return new self('Faktura nemá uložený e-mail příjemce. Správce může zadat jednorázovou adresu.');
    }
}
