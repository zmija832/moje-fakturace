<?php

namespace App\Domain\BusinessContext;

use App\Models\Business;
use LogicException;

class ActiveBusinessContext
{
    private ?Business $business = null;

    public function set(Business $business): void
    {
        if (! in_array($business->connection_name, config('business.allowed_connections'), true)) {
            throw new LogicException('Subjekt používá nepovolené databázové připojení.');
        }

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
            ?? throw new LogicException('Není zvolen aktivní fakturační subjekt.');
    }
}
