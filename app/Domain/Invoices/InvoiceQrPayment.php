<?php

namespace App\Domain\Invoices;

final readonly class InvoiceQrPayment
{
    private function __construct(
        public bool $available,
        public ?string $payload,
        public ?string $svgDataUri,
        public ?string $reason,
    ) {}

    public static function available(string $payload, string $svgDataUri): self
    {
        return new self(true, $payload, $svgDataUri, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, null, null, $reason);
    }
}
