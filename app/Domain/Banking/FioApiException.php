<?php

namespace App\Domain\Banking;

use RuntimeException;

final class FioApiException extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct('Fio API požadavek se nepodařilo bezpečně zpracovat.');
    }
}
