<?php

namespace App\Services\Business;

use App\Domain\Invoices\Exceptions\InvoiceNotReadyForIssue;
use App\Enums\DocumentType;
use App\Models\Business\DocumentSequence;
use App\Models\Business\Invoice;

class InvoiceIssueAvailability
{
    public function __construct(private readonly InvoiceIssueReadinessValidator $readinessValidator) {}

    /** @return array{can_issue: bool, reason: ?string} */
    public function for(Invoice $invoice): array
    {
        try {
            $this->readinessValidator->validate($invoice);
        } catch (InvoiceNotReadyForIssue $exception) {
            return ['can_issue' => false, 'reason' => $this->readinessReason($exception->reason)];
        }

        $hasSequence = DocumentSequence::query()
            ->where('document_type', DocumentType::IssuedInvoice->value)
            ->whereNull('archived_at')
            ->where('is_active', true)
            ->exists();

        return $hasSequence
            ? ['can_issue' => true, 'reason' => null]
            : ['can_issue' => false, 'reason' => 'Není nastavena aktivní číselná řada pro vydané faktury.'];
    }

    public function readinessReason(string $reason): string
    {
        return match ($reason) {
            'chybí aktuální revize' => 'Faktura nemá aktuální revizi.',
            'chybí snapshot dodavatele nebo odběratele' => 'Chybí údaje dodavatele nebo odběratele.',
            'faktura nemá žádnou položku' => 'Faktura nemá žádnou položku.',
            'měna není podporována' => 'Měna faktury není podporována.',
            'platební metoda není podporována' => 'Způsob úhrady není podporován.',
            'chybí datum zdanitelného plnění' => 'Chybí datum zdanitelného plnění.',
            'datum splatnosti předchází datu vystavení' => 'Datum splatnosti nesmí předcházet datu vystavení.',
            'bankovní převod vyžaduje snapshot bankovního účtu' => 'Pro bankovní převod chybí bankovní účet.',
            default => 'Uložené údaje faktury neprošly kontrolou před vystavením.',
        };
    }
}
