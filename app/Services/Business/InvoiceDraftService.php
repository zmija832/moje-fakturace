<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceDraftService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly InvoiceRevisionFactory $revisionFactory,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Invoice
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($attributes): Invoice {
            $prepared = $this->revisionFactory->prepare($attributes);
            $invoice = new Invoice;
            $invoice->forceFill([
                'document_type' => DocumentType::IssuedInvoice->value,
                'status' => InvoiceStatus::Draft->value,
                'version' => 1,
                ...$prepared['header'],
            ])->save();
            $revision = $this->revisionFactory->persist($invoice, 1, $prepared);
            $invoice->forceFill(['current_revision_id' => $revision->id])->save();
            $invoice->load([
                'currentRevision.supplierSnapshot', 'currentRevision.customerSnapshot',
                'currentRevision.bankAccountSnapshot', 'currentRevision.vatSnapshots',
                'currentRevision.items.vatSnapshot', 'currentRevision.vatSummaries',
            ]);
            $snapshot = $this->auditSanitizer->snapshot(BusinessAuditableType::Invoice, $invoice);
            $this->auditWriter->write(
                BusinessAuditEvent::InvoiceDraftCreated,
                BusinessAuditableType::Invoice,
                $invoice->uuid,
                null,
                $snapshot,
                array_keys($snapshot),
            );

            return $invoice;
        }, 3);
    }
}
