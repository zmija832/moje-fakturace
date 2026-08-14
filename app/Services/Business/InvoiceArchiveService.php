<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceArchiveService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    public function archiveDraft(string $invoiceUuid): Invoice
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($invoiceUuid): Invoice {
            $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== InvoiceStatus::Draft || $invoice->archived_at !== null) {
                throw ValidationException::withMessages([
                    'invoice' => 'Archivovat lze pouze aktivní koncept faktury.',
                ]);
            }

            $before = $this->auditSanitizer->snapshot(BusinessAuditableType::Invoice, $invoice);
            $invoice->forceFill(['archived_at' => now()])->save();
            $this->auditWriter->write(
                BusinessAuditEvent::InvoiceDraftArchived,
                BusinessAuditableType::Invoice,
                $invoice->uuid,
                $before,
                $this->auditSanitizer->snapshot(BusinessAuditableType::Invoice, $invoice),
                ['archived_at'],
            );

            return $invoice;
        }, 3);
    }
}
