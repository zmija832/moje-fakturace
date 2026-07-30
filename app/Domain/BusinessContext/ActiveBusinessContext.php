<?php

namespace App\Domain\BusinessContext;

use App\Domain\BusinessContext\Exceptions\MissingBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;

class ActiveBusinessContext
{
    private ?Business $business = null;

    public function set(Business $business): void
    {
        BusinessConnection::fromConfiguredValue($business->connection_name);

        $this->business = $business;
    }

    public function clear(): void
    {
        $this->business = null;
    }

    public function business(): ?Business
    {
        return $this->business;
    }

    public function id(): ?int
    {
        return $this->business?->id;
    }

    public function uuid(): ?string
    {
        return $this->business?->uuid;
    }

    public function displayName(): ?string
    {
        return $this->business?->display_name;
    }

    public function registrationNumber(): ?string
    {
        return $this->business?->registration_number;
    }

    public function connectionName(): ?string
    {
        return $this->business?->connection_name;
    }

    public function requireBusiness(): Business
    {
        return $this->business
            ?? throw new MissingBusinessContext;
    }
}
