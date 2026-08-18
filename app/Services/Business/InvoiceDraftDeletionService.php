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

class InvoiceDraftDeletionService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly InvoiceAggregateDeletion $aggregateDeletion,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    public function delete(string $invoiceUuid): void
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        try {
            DB::connection($connection)->transaction(function () use ($connection, $invoiceUuid): void {
                $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->firstOrFail();
                if ($invoice->status !== InvoiceStatus::Draft) {
                    throw ValidationException::withMessages(['invoice' => 'Tímto způsobem lze odstranit pouze koncept bez čísla dokladu.']);
                }
                if ($invoice->documents()->exists() || $invoice->payments()->exists()
                    || $invoice->emailDeliveries()->exists() || $invoice->publicLinks()->exists()) {
                    throw ValidationException::withMessages(['invoice' => 'Koncept obsahuje neočekávané navázané záznamy a nelze jej bezpečně odstranit.']);
                }

                $revisionIds = $invoice->revisions()->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $snapshot = $this->auditSanitizer->snapshot(BusinessAuditableType::Invoice, $invoice);
                $this->auditWriter->write(
                    BusinessAuditEvent::InvoiceDraftDeleted,
                    BusinessAuditableType::Invoice,
                    $invoice->uuid,
                    $snapshot,
                    null,
                    ['deleted'],
                    metadata: ['revision_count' => count($revisionIds)],
                );

                DB::connection($connection)->statement(
                    "SET @invoice_destructive_operation = 'draft_delete', @invoice_destructive_uuid = ?",
                    [$invoice->uuid],
                );
                DB::connection($connection)->table('invoices')->where('id', $invoice->id)
                    ->update(['current_revision_id' => null, 'updated_at' => now()]);
                DB::connection($connection)->table('invoice_draft_operations')->where('invoice_id', $invoice->id)->delete();
                $this->aggregateDeletion->deleteRevisions($connection, $revisionIds);
                DB::connection($connection)->table('invoices')->where('id', $invoice->id)->delete();
            }, 3);
        } finally {
            DB::connection($connection)->statement(
                'SET @invoice_destructive_operation = NULL, @invoice_destructive_uuid = NULL',
            );
        }
    }
}
