<?php

namespace App\Domain\BusinessContext\Exceptions;

use LogicException;

class InvalidBusinessConnection extends LogicException
{
    public static function create(): self
    {
        return new self('Zvolené databázové připojení není povolené business připojení.');
    }
}
