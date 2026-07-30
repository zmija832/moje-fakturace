<?php

namespace App\Domain\BusinessContext\Exceptions;

use LogicException;

class MissingBusinessContext extends LogicException
{
    public function __construct()
    {
        parent::__construct('Není zvolen aktivní fakturační subjekt.');
    }
}
