<?php

namespace App\Domain\BusinessContext;

use App\Domain\BusinessContext\Exceptions\MissingBusinessContext;
use App\Enums\BusinessConnection;

class BusinessConnectionResolver
{
    public function __construct(private readonly ActiveBusinessContext $context) {}

    public function resolve(): BusinessConnection
    {
        $connectionName = $this->context->connectionName();

        if ($connectionName === null) {
            throw new MissingBusinessContext;
        }

        return BusinessConnection::fromConfiguredValue($connectionName);
    }
}
