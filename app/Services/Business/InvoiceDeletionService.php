<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\DocumentSequenceResetPeriod;
use App\Enums\InvoiceStatus;
use App\Models\Business\DocumentSequence;
use App\Models\Business\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvoiceDeletionService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly InvoiceAggregateDeletion $aggregateDeletion,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    /** @return array{document_number: ?string, allocation_released: bool} */
    public function delete(string $invoiceUuid): array
    {
        $connection = $this->connectionResolver->resolve()->connectionName();
        $candidate = Invoice::query()->where('uuid', $invoiceUuid)->firstOrFail();
        $sequenceId = $candidate->document_sequence_id;
        $quarantined = [];

        try {
            $result = DB::connection($connection)->transaction(function () use ($connection, $invoiceUuid, $sequenceId, &$quarantined): array {
                $sequence = $sequenceId === null ? null : DocumentSequence::query()->whereKey($sequenceId)->lockForUpdate()->firstOrFail();
                $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->firstOrFail();

                if ($invoice->document_sequence_id !== $sequenceId) {
                    throw ValidationException::withMessages(['invoice' => 'Faktura se mezitím změnila. Opakujte akci nad aktuálním stavem.']);
                }

                $documents = $invoice->documents()->lockForUpdate()->get();
                foreach ($documents as $document) {
                    if ($document->storage_disk !== InvoicePdfGenerator::DISK) {
                        throw ValidationException::withMessages(['invoice' => 'PDF dokument používá neočekávané úložiště. Mazání bylo zastaveno.']);
                    }
                    if (Storage::disk(InvoicePdfGenerator::DISK)->exists($document->storage_path)) {
                        $quarantine = '.delete-quarantine/'.$invoice->uuid.'/'.$document->uuid.'.pdf';
                        if (! Storage::disk(InvoicePdfGenerator::DISK)->move($document->storage_path, $quarantine)) {
                            throw ValidationException::withMessages(['invoice' => 'PDF se nepodařilo bezpečně připravit k odstranění.']);
                        }
                        $quarantined[] = [$quarantine, $document->storage_path];
                    }
                }

                $revisionIds = $invoice->revisions()->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $allocation = $invoice->document_number_allocation_id === null ? null : DB::connection($connection)
                    ->table('document_number_allocations')->where('id', $invoice->document_number_allocation_id)->lockForUpdate()->first();
                if ($invoice->status->hasIssuedDocument() && $allocation === null) {
                    throw ValidationException::withMessages(['invoice' => 'Vystavená faktura nemá platnou alokaci čísla. Mazání bylo zastaveno.']);
                }

                $snapshot = $this->auditSanitizer->snapshot(BusinessAuditableType::Invoice, $invoice);
                $this->auditWriter->write(
                    BusinessAuditEvent::InvoiceDeleted,
                    BusinessAuditableType::Invoice,
                    $invoice->uuid,
                    $snapshot,
                    null,
                    ['deleted'],
                    $allocation === null ? null : BusinessAuditableType::DocumentNumberAllocation,
                    $allocation?->correlation_uuid,
                    [
                        'document_number' => $invoice->document_number,
                        'allocation_released' => $allocation !== null,
                        'sequence_number' => $allocation?->sequence_number,
                    ],
                );

                $destructiveOperation = $invoice->status === InvoiceStatus::Draft ? 'draft_delete' : 'invoice_delete';
                DB::connection($connection)->statement(
                    'SET @invoice_destructive_operation = ?, @invoice_destructive_uuid = ?',
                    [$destructiveOperation, $invoice->uuid],
                );

                if ($invoice->status->hasIssuedDocument()) {
                    DB::connection($connection)->table('invoices')->where('id', $invoice->id)->update([
                        'status' => 'purging',
                        'current_revision_id' => null,
                        'issued_revision_id' => null,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::connection($connection)->table('invoices')->where('id', $invoice->id)->update([
                        'current_revision_id' => null,
                        'updated_at' => now(),
                    ]);
                }

                DB::connection($connection)->table('invoice_email_deliveries')->where('invoice_id', $invoice->id)->delete();
                DB::connection($connection)->table('invoice_payments')->where('invoice_id', $invoice->id)->whereNotNull('reverses_payment_id')->delete();
                DB::connection($connection)->table('invoice_payments')->where('invoice_id', $invoice->id)->delete();
                DB::connection($connection)->table('invoice_issued_revision_operations')->where('invoice_id', $invoice->id)->delete();
                DB::connection($connection)->table('invoice_public_links')->where('invoice_id', $invoice->id)->delete();
                DB::connection($connection)->table('invoice_documents')->where('invoice_id', $invoice->id)->delete();
                DB::connection($connection)->table('invoice_draft_operations')->where('invoice_id', $invoice->id)->delete();
                $this->aggregateDeletion->deleteRevisions($connection, $revisionIds);
                DB::connection($connection)->table('invoices')->where('id', $invoice->id)->delete();

                if ($allocation !== null && $sequence !== null) {
                    DB::connection($connection)->table('document_number_allocations')->where('id', $allocation->id)->delete();
                    $shouldRewind = $sequence->reset_period === DocumentSequenceResetPeriod::Never
                        || $sequence->current_period === $allocation->period;
                    if ($shouldRewind) {
                        $last = DB::connection($connection)->table('document_number_allocations')
                            ->where('document_sequence_id', $sequence->id)
                            ->where('period', $allocation->period)
                            ->max('sequence_number');
                        $nextNumber = $last === null ? $sequence->start_number : ((int) $last) + 1;
                        DB::connection($connection)->table('document_sequences')->where('id', $sequence->id)
                            ->update(['next_number' => $nextNumber, 'updated_at' => now()]);
                    }
                }

                return ['document_number' => $invoice->document_number, 'allocation_released' => $allocation !== null];
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

        return $result;
    }
}
