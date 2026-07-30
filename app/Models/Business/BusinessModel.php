<?php

namespace App\Models\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\BusinessContext\Exceptions\InvalidBusinessConnection;
use Illuminate\Database\Eloquent\Model;

abstract class BusinessModel extends Model
{
    public function getConnectionName(): string
    {
        return $this->businessConnectionResolver()->resolve()->connectionName();
    }

    public function setConnection($name): static
    {
        $resolvedConnection = $this->getConnectionName();

        if ($name !== $resolvedConnection) {
            throw InvalidBusinessConnection::create();
        }

        parent::setConnection($resolvedConnection);

        return $this;
    }

    private function businessConnectionResolver(): BusinessConnectionResolver
    {
        return app(BusinessConnectionResolver::class);
    }
}
