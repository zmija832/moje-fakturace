<?php

namespace App\Domain\Invoices;

final readonly class InvoicePaymentEventSnapshot
{
    /** @param list<string> $notificationIntents */
    public function __construct(
        public string $invoiceUuid,
        public string $documentNumber,
        public string $paymentUuid,
        public string $paymentType,
        public string $amount,
        public string $currency,
        public string $paidOn,
        public string $statusBefore,
        public string $statusAfter,
        public string $paidTotal,
        public string $remainingTotal,
        public array $notificationIntents,
    ) {}
}
