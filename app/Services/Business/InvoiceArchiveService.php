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

    public function archive(string $invoiceUuid): Invoice
    {
        return $this->change($invoiceUuid, true);
    }

    public function restore(string $invoiceUuid): Invoice
    {
        return $this->change($invoiceUuid, false);
    }

    public function archiveDraft(string $invoiceUuid): Invoice
    {
        return $this->archive($invoiceUuid);
    }

    private function change(string $invoiceUuid, bool $archive): Invoice
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        try {
            return DB::connection($connection)->transaction(function () use ($connection, $invoiceUuid, $archive): Invoice {
                $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->firstOrFail();
                if (($archive && $invoice->archived_at !== null) || (! $archive && $invoice->archived_at === null)) {
                    throw ValidationException::withMessages(['invoice' => $archive ? 'Faktura již je archivovaná.' : 'Faktura není archivovaná.']);
                }

                $before = $this->auditSanitizer->snapshot(BusinessAuditableType::Invoice, $invoice);
                if ($invoice->status === InvoiceStatus::Issued) {
                    DB::connection($connection)->statement("SET @invoice_admin_operation = 'archive', @invoice_admin_uuid = ?", [$invoice->uuid]);
                }
                DB::connection($connection)->table('invoices')->where('id', $invoice->id)->update([
                    'archived_at' => $archive ? now() : null,
                    'updated_at' => now(),
                ]);
                $invoice = Invoice::query()->whereKey($invoice->id)->firstOrFail();
                $event = $archive
                    ? ($invoice->status === InvoiceStatus::Draft ? BusinessAuditEvent::InvoiceDraftArchived : BusinessAuditEvent::InvoiceArchived)
                    : BusinessAuditEvent::InvoiceRestored;
                $this->auditWriter->write(
                    $event,
                    BusinessAuditableType::Invoice,
                    $invoice->uuid,
                    $before,
                    $this->auditSanitizer->snapshot(BusinessAuditableType::Invoice, $invoice),
                    ['archived_at'],
                );

                return $invoice;
            }, 3);
        } finally {
            DB::connection($connection)->statement('SET @invoice_admin_operation = NULL, @invoice_admin_uuid = NULL');
        }
    }
}
