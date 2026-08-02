<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\Exceptions\InvoiceDraftIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceDraftVersionConflict;
use App\Domain\Invoices\Exceptions\InvoiceNotDraft;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceDraftOperation;
use App\Models\Business\InvoiceRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceDraftEditor
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly InvoiceRevisionFactory $revisionFactory,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function update(string $invoiceUuid, int $expectedVersion, string $correlationUuid, array $attributes): InvoiceRevision
    {
        if ($expectedVersion < 1) {
            throw ValidationException::withMessages(['version' => 'Očekávaná verze musí být kladné celé číslo.']);
        }

        if (! Str::isUuid($invoiceUuid) || ! Str::isUuid($correlationUuid)) {
            throw ValidationException::withMessages(['correlation_uuid' => 'Technické UUID operace má neplatný formát.']);
        }

        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($invoiceUuid, $expectedVersion, $correlationUuid, $attributes): InvoiceRevision {
            $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw InvoiceNotDraft::forEdit();
            }

            $operation = InvoiceDraftOperation::query()
                ->where('correlation_uuid', $correlationUuid)
                ->lockForUpdate()
                ->first();

            if ($operation !== null) {
                if ((int) $operation->invoice_id !== (int) $invoice->id) {
                    throw InvoiceDraftIdempotencyConflict::reusedCorrelation();
                }

                return $operation->revision()->firstOrFail()->load([
                    'supplierSnapshot', 'customerSnapshot', 'bankAccountSnapshot', 'vatSnapshots',
                    'items.vatSnapshot', 'vatSummaries',
                ]);
            }

            if ($invoice->version !== $expectedVersion) {
                throw InvoiceDraftVersionConflict::forVersions($expectedVersion, $invoice->version);
            }

            $currentRevision = InvoiceRevision::query()
                ->whereKey($invoice->current_revision_id)
                ->where('invoice_id', $invoice->id)
                ->firstOrFail();
            $prepared = $this->revisionFactory->prepare($attributes);

            if ($this->revisionFactory->matches($currentRevision, $prepared)) {
                $this->storeOperation($correlationUuid, $invoice, $currentRevision);

                return $currentRevision;
            }

            $newVersion = $invoice->version + 1;
            $revision = $this->revisionFactory->persist($invoice, $newVersion, $prepared);
            $this->storeOperation($correlationUuid, $invoice, $revision);
            $updated = Invoice::query()
                ->whereKey($invoice->id)
                ->where('version', $expectedVersion)
                ->update([
                    'current_revision_id' => $revision->id,
                    'version' => $newVersion,
                    ...$prepared['header'],
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw InvoiceDraftVersionConflict::forVersions($expectedVersion, $newVersion);
            }

            $oldValues = $this->auditSanitizer->invoiceRevision($currentRevision);
            $newValues = $this->auditSanitizer->invoiceRevision($revision);
            $this->auditWriter->write(
                BusinessAuditEvent::InvoiceDraftRevisionCreated,
                BusinessAuditableType::Invoice,
                $invoice->uuid,
                $oldValues,
                $newValues,
                $this->changedFields($oldValues, $newValues),
                metadata: ['correlation_uuid' => $correlationUuid],
            );

            return $revision;
        }, 3);
    }

    private function storeOperation(string $correlationUuid, Invoice $invoice, InvoiceRevision $revision): void
    {
        $operation = new InvoiceDraftOperation;
        $operation->forceFill([
            'correlation_uuid' => $correlationUuid,
            'invoice_id' => $invoice->id,
            'invoice_revision_id' => $revision->id,
        ])->save();
    }

    /** @param array<string, mixed> $oldValues @param array<string, mixed> $newValues @return list<string> */
    private function changedFields(array $oldValues, array $newValues): array
    {
        return array_values(array_filter(
            array_keys($newValues),
            fn (string $field): bool => ($oldValues[$field] ?? null) !== ($newValues[$field] ?? null),
        ));
    }
}
