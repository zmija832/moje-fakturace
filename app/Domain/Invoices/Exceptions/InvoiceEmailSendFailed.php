<?php

namespace App\Domain\Invoices\Exceptions;

use RuntimeException;

class InvoiceEmailSendFailed extends RuntimeException
{
    public static function create(): self
    {
        return new self('E-mail se nepodařilo odeslat. Pokus byl bezpečně zaznamenán.');
    }
}
