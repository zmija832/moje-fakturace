<?php

namespace App\Domain\Invoices;

final readonly class InvoiceDocumentViewModel
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
