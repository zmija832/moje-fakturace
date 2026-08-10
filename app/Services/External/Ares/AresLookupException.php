<?php

namespace App\Services\External\Ares;

use RuntimeException;

final class AresLookupException extends RuntimeException
{
    private function __construct(public readonly bool $notFound, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(true, 'Subjekt s tímto IČO nebyl v ARES nalezen.');
    }

    public static function unavailable(): self
    {
        return new self(false, 'ARES nyní není dostupný. Údaje můžete vyplnit ručně.');
    }
}
