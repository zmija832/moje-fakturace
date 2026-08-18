<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Models\Business\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvoiceTestPurgeService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly InvoiceAggregateDeletion $aggregateDeletion,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    public function purge(string $invoiceUuid, string $confirmedDocumentNumber): string
    {
        $connection = $this->connectionResolver->resolve()->connectionName();
        $quarantined = [];

        try {
            $documentNumber = DB::connection($connection)->transaction(function () use ($connection, $invoiceUuid, $confirmedDocumentNumber, &$quarantined): string {
                $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->firstOrFail();
                if (! in_array(strtolower($invoice->uuid), config('business.invoice_test_purge_uuids', []), true)) {
                    throw ValidationException::withMessages(['invoice' => 'Tato faktura není serverem výslovně označena jako testovací pro purge.']);
                }
                if (! $invoice->status->hasIssuedDocument() || $invoice->document_number === null
                    || ! hash_equals($invoice->document_number, $confirmedDocumentNumber)) {
                    throw ValidationException::withMessages(['document_number' => 'Pro potvrzení opište přesné číslo testovací faktury.']);
                }
                if ($invoice->payments()->exists()) {
                    throw ValidationException::withMessages(['invoice' => 'Test purge je zakázán pro fakturu s jakoukoli platební historií.']);
                }
                if ($invoice->emailDeliveries()->exists()) {
                    throw ValidationException::withMessages(['invoice' => 'Test purge je zakázán pro fakturu s historií odeslání.']);
                }
                if (DB::connection($connection)->table('invoice_issued_revision_operations')->where('invoice_id', $invoice->id)->exists()) {
                    throw ValidationException::withMessages(['invoice' => 'Test purge je zakázán pro fakturu s historií admin úprav vystaveného dokladu.']);
                }

                $documents = $invoice->documents()->lockForUpdate()->get();
                foreach ($documents as $document) {
                    if ($document->storage_disk !== InvoicePdfGenerator::DISK) {
                        throw ValidationException::withMessages(['invoice' => 'PDF dokument používá neočekávané úložiště. Purge byl zastaven.']);
                    }
                    if (Storage::disk(InvoicePdfGenerator::DISK)->exists($document->storage_path)) {
                        $quarantine = '.purge-quarantine/'.$invoice->uuid.'/'.$document->uuid.'.pdf';
                        if (! Storage::disk(InvoicePdfGenerator::DISK)->move($document->storage_path, $quarantine)) {
                            throw ValidationException::withMessages(['invoice' => 'PDF se nepodařilo bezpečně připravit k odstranění.']);
                        }
                        $quarantined[] = [$quarantine, $document->storage_path];
                    }
                }

                $revisionIds = $invoice->revisions()->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $snapshot = $this->auditSanitizer->snapshot(BusinessAuditableType::Invoice, $invoice);
                $this->auditWriter->write(
                    BusinessAuditEvent::InvoiceTestPurged,
                    BusinessAuditableType::Invoice,
                    $invoice->uuid,
                    $snapshot,
                    null,
                    ['purged'],
                    BusinessAuditableType::DocumentNumberAllocation,
                    $invoice->numberAllocation?->correlation_uuid,
                    ['document_number' => $invoice->document_number, 'allocation_preserved' => true],
                );

                DB::connection($connection)->statement(
                    "SET @invoice_destructive_operation = 'test_purge', @invoice_destructive_uuid = ?",
                    [$invoice->uuid],
                );
                DB::connection($connection)->table('invoices')->where('id', $invoice->id)->update([
                    'status' => 'purging', 'current_revision_id' => null, 'issued_revision_id' => null, 'updated_at' => now(),
                ]);
                DB::connection($connection)->table('invoice_public_links')->where('invoice_id', $invoice->id)->delete();
                DB::connection($connection)->table('invoice_documents')->where('invoice_id', $invoice->id)->delete();
                DB::connection($connection)->table('invoice_draft_operations')->where('invoice_id', $invoice->id)->delete();
                $this->aggregateDeletion->deleteRevisions($connection, $revisionIds);
                DB::connection($connection)->table('invoices')->where('id', $invoice->id)->delete();

                return $invoice->document_number;
            }, 3);
        } catch (Throwable $exception) {
            foreach (array_reverse($quarantined) as [$quarantine, $original]) {
                if (Storage::disk(InvoicePdfGenerator::DISK)->exists($quarantine)) {
                    Storage::disk(InvoicePdfGenerator::DISK)->move($quarantine, $original);
                }
            }
            throw $exception;
        } finally {
            DB::connection($connection)->statement(
                'SET @invoice_destructive_operation = NULL, @invoice_destructive_uuid = NULL',
            );
        }

        foreach ($quarantined as [$quarantine]) {
            Storage::disk(InvoicePdfGenerator::DISK)->delete($quarantine);
        }

        return $documentNumber;
    }
}
