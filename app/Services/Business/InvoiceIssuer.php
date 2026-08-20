<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\Exceptions\InvoiceIssueIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceIssueSequenceUnavailable;
use App\Domain\Invoices\Exceptions\InvoiceIssueVersionConflict;
use App\Domain\Invoices\Exceptions\InvoiceNotDraft;
use App\Domain\Invoices\InvoiceVariableSymbol;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Models\Business\DocumentNumberAllocation;
use App\Models\Business\DocumentSequence;
use App\Models\Business\DocumentSequenceDefault;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceRevision;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvoiceIssuer
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly DocumentNumberAllocator $numberAllocator,
        private readonly InvoiceIssueReadinessValidator $readinessValidator,
        private readonly InvoiceRevisionFactory $revisionFactory,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
        private readonly InvoicePublicLinkService $publicLinks,
    ) {}

    public function issue(
        string $invoiceUuid,
        int $expectedVersion,
        string $correlationUuid,
        ?string $documentSequenceUuid = null,
    ): Invoice {
        $this->validateInput($invoiceUuid, $expectedVersion, $correlationUuid, $documentSequenceUuid);
        $connection = $this->connectionResolver->resolve()->connectionName();

        try {
            return DB::connection($connection)->transaction(
                function () use ($invoiceUuid, $expectedVersion, $correlationUuid, $documentSequenceUuid): Invoice {
                    $invoice = $this->issueLocked(
                        $invoiceUuid,
                        $expectedVersion,
                        $correlationUuid,
                        $documentSequenceUuid,
                    );
                    $this->publicLinks->create($invoice);

                    return $invoice;
                },
                3,
            );
        } catch (InvoiceNotDraft|InvoiceIssueVersionConflict|InvoiceIssueIdempotencyConflict $exception) {
            $this->auditConflict($connection, $invoiceUuid, $correlationUuid, $exception);

            throw $exception;
        }
    }

    private function issueLocked(
        string $invoiceUuid,
        int $expectedVersion,
        string $correlationUuid,
        ?string $documentSequenceUuid,
    ): Invoice {
        $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->firstOrFail();

        if ($invoice->status === InvoiceStatus::Issued && $invoice->issue_correlation_uuid === $correlationUuid) {
            return $this->loadIssued($invoice);
        }
        if ($invoice->status !== InvoiceStatus::Draft || $invoice->archived_at !== null) {
            throw InvoiceNotDraft::forIssue();
        }

        $correlationOwner = Invoice::query()
            ->where('issue_correlation_uuid', $correlationUuid)
            ->lockForUpdate()
            ->first();

        if ($correlationOwner !== null && (int) $correlationOwner->id !== (int) $invoice->id) {
            throw InvoiceIssueIdempotencyConflict::reusedCorrelation();
        }
        if ($invoice->version !== $expectedVersion) {
            throw InvoiceIssueVersionConflict::forVersions($expectedVersion, $invoice->version);
        }

        $revision = $this->readinessValidator->validate($invoice);
        $sequence = $this->sequence($documentSequenceUuid);
        $this->assertCorrelationAvailable($correlationUuid, $invoice);

        $allocation = $this->numberAllocator->allocate(
            $sequence->uuid,
            $revision->issued_on,
            $correlationUuid,
            $invoice->uuid,
        );
        if ($revision->variable_symbol === null) {
            $revision = $this->revisionFactory->persistForAutomaticVariableSymbol(
                $invoice,
                $revision,
                InvoiceVariableSymbol::fromDocumentNumber($allocation->formatted_number),
            );
        }
        $newVersion = $invoice->version + 1;
        $issuedAt = now();

        $this->persistIssue($invoice, $revision, $allocation, $correlationUuid, $newVersion, $issuedAt);
        $invoice = Invoice::query()->whereKey($invoice->id)->firstOrFail();
        $this->auditWriter->write(
            BusinessAuditEvent::InvoiceIssued,
            BusinessAuditableType::Invoice,
            $invoice->uuid,
            ['status' => InvoiceStatus::Draft->value, 'version' => $newVersion - 1],
            $this->auditSanitizer->issuedInvoice($invoice, $allocation),
            ['status', 'document_number', 'document_number_allocation_id', 'current_revision_id', 'issued_revision_id', 'variable_symbol', 'issued_at', 'version'],
            BusinessAuditableType::DocumentNumberAllocation,
            $allocation->correlation_uuid,
        );

        return $this->loadIssued($invoice);
    }

    private function validateInput(string $invoiceUuid, int $expectedVersion, string $correlationUuid, ?string $sequenceUuid): void
    {
        if (! Str::isUuid($invoiceUuid) || ! Str::isUuid($correlationUuid) || ($sequenceUuid !== null && ! Str::isUuid($sequenceUuid))) {
            throw ValidationException::withMessages(['correlation_uuid' => 'UUID vystavení má neplatný formát.']);
        }
        if ($expectedVersion < 1) {
            throw ValidationException::withMessages(['expected_version' => 'Očekávaná verze musí být kladné celé číslo.']);
        }
    }

    private function sequence(?string $sequenceUuid): DocumentSequence
    {
        if ($sequenceUuid === null) {
            $default = DocumentSequenceDefault::query()
                ->where('document_type', DocumentType::IssuedInvoice->value)
                ->lockForUpdate()
                ->first();

            if ($default === null) {
                throw InvoiceIssueSequenceUnavailable::unavailable();
            }
            $sequence = DocumentSequence::query()->whereKey($default->document_sequence_id)->lockForUpdate()->first();
        } else {
            $sequence = DocumentSequence::query()->where('uuid', $sequenceUuid)->lockForUpdate()->first();
        }

        if (
            $sequence === null
            || $sequence->document_type !== DocumentType::IssuedInvoice
            || ! $sequence->is_active
            || $sequence->isArchived()
        ) {
            throw InvoiceIssueSequenceUnavailable::unavailable();
        }

        return $sequence;
    }

    private function assertCorrelationAvailable(string $correlationUuid, Invoice $invoice): void
    {
        $allocation = DocumentNumberAllocation::query()
            ->where('correlation_uuid', $correlationUuid)
            ->lockForUpdate()
            ->first();

        if ($allocation !== null && $allocation->document_uuid !== $invoice->uuid) {
            throw InvoiceIssueIdempotencyConflict::reusedCorrelation();
        }
    }

    private function persistIssue(Invoice $invoice, InvoiceRevision $revision, DocumentNumberAllocation $allocation, string $correlationUuid, int $version, DateTimeInterface $issuedAt): void
    {
        $updated = Invoice::query()
            ->whereKey($invoice->id)
            ->where('status', InvoiceStatus::Draft->value)
            ->where('version', $invoice->version)
            ->update([
                'status' => InvoiceStatus::Issued->value,
                'document_number' => $allocation->formatted_number,
                'document_sequence_id' => $allocation->document_sequence_id,
                'document_number_allocation_id' => $allocation->id,
                'current_revision_id' => $revision->id,
                'issued_revision_id' => $revision->id,
                'variable_symbol' => $revision->variable_symbol,
                'issued_at' => $issuedAt,
                'issue_correlation_uuid' => $correlationUuid,
                'version' => $version,
                'updated_at' => $issuedAt,
            ]);

        if ($updated !== 1) {
            throw InvoiceIssueVersionConflict::forVersions($invoice->version, $version);
        }
    }

    private function loadIssued(Invoice $invoice): Invoice
    {
        return $invoice->refresh()->load([
            'numberAllocation', 'documentSequence', 'issuedRevision.supplierSnapshot',
            'issuedRevision.customerSnapshot', 'issuedRevision.bankAccountSnapshot',
            'issuedRevision.vatSnapshots', 'issuedRevision.items.vatSnapshot',
            'issuedRevision.vatSummaries',
        ]);
    }

    private function auditConflict(string $connection, string $invoiceUuid, string $correlationUuid, Throwable $exception): void
    {
        try {
            DB::connection($connection)->transaction(function () use ($invoiceUuid, $correlationUuid, $exception): void {
                $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->first();

                if ($invoice === null) {
                    return;
                }

                $reason = match (true) {
                    $exception instanceof InvoiceIssueVersionConflict => 'version_conflict',
                    $exception instanceof InvoiceIssueIdempotencyConflict => 'idempotency_conflict',
                    default => 'invoice_not_draft',
                };
                $this->auditWriter->write(
                    BusinessAuditEvent::InvoiceIssueConflict,
                    BusinessAuditableType::Invoice,
                    $invoice->uuid,
                    null,
                    ['status' => $invoice->status->value, 'version' => $invoice->version],
                    [],
                    metadata: ['reason' => $reason, 'correlation_uuid' => $correlationUuid],
                );
            }, 3);
        } catch (Throwable) {
            // Konfliktní pokus nesmí změnit výsledek ani atomickou transakci vystavení.
        }
    }
}
