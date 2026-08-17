<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\Exceptions\InvoiceIssuedRevisionIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceIssuedRevisionVersionConflict;
use App\Domain\Invoices\Exceptions\InvoiceNotIssuedForDelivery;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceIssuedRevisionOperation;
use App\Models\Business\InvoiceRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceIssuedRevisionService
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
        if ($expectedVersion < 1 || ! Str::isUuid($invoiceUuid) || ! Str::isUuid($correlationUuid)) {
            throw ValidationException::withMessages(['correlation_uuid' => 'Technické údaje admin úpravy nejsou platné.']);
        }

        $connection = $this->connectionResolver->resolve()->connectionName();

        try {
            return DB::connection($connection)->transaction(function () use ($connection, $invoiceUuid, $expectedVersion, $correlationUuid, $attributes): InvoiceRevision {
                $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->firstOrFail();
                if ($invoice->status !== InvoiceStatus::Issued || $invoice->archived_at !== null) {
                    throw InvoiceNotIssuedForDelivery::create();
                }

                $operation = InvoiceIssuedRevisionOperation::query()
                    ->where('correlation_uuid', $correlationUuid)->lockForUpdate()->first();
                if ($operation !== null) {
                    if ((int) $operation->invoice_id !== (int) $invoice->id) {
                        throw InvoiceIssuedRevisionIdempotencyConflict::reusedCorrelation();
                    }

                    return $operation->revision()->firstOrFail()->load([
                        'supplierSnapshot', 'customerSnapshot', 'bankAccountSnapshot', 'vatSnapshots',
                        'items.vatSnapshot', 'vatSummaries',
                    ]);
                }
                if ($invoice->version !== $expectedVersion) {
                    throw InvoiceIssuedRevisionVersionConflict::forVersions($expectedVersion, $invoice->version);
                }

                $current = InvoiceRevision::query()
                    ->whereKey($invoice->issued_revision_id)->where('invoice_id', $invoice->id)
                    ->lockForUpdate()->firstOrFail();
                $prepared = $this->revisionFactory->prepare($attributes);
                if ($this->revisionFactory->matches($current, $prepared)) {
                    $this->storeOperation($correlationUuid, $invoice, $current);

                    return $current;
                }

                $revisionNumber = (int) InvoiceRevision::query()->where('invoice_id', $invoice->id)->max('revision_number') + 1;
                $newVersion = $invoice->version + 1;
                DB::connection($connection)->statement("SET @invoice_admin_operation = 'revise', @invoice_admin_uuid = ?", [$invoice->uuid]);
                $revision = $this->revisionFactory->persistIssuedCorrection($invoice, $revisionNumber, $prepared);
                $this->storeOperation($correlationUuid, $invoice, $revision);

                $updated = DB::connection($connection)->table('invoices')
                    ->where('id', $invoice->id)->where('version', $expectedVersion)->update([
                        'current_revision_id' => $revision->id,
                        'issued_revision_id' => $revision->id,
                        'version' => $newVersion,
                        ...$prepared['header'],
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    throw InvoiceIssuedRevisionVersionConflict::forVersions($expectedVersion, $newVersion);
                }

                $oldValues = $this->auditSanitizer->invoiceRevision($current);
                $newValues = $this->auditSanitizer->invoiceRevision($revision);
                $this->auditWriter->write(
                    BusinessAuditEvent::InvoiceIssuedRevisionCreated,
                    BusinessAuditableType::Invoice,
                    $invoice->uuid,
                    $oldValues,
                    $newValues,
                    $this->changedFields($oldValues, $newValues),
                    metadata: ['correlation_uuid' => $correlationUuid, 'previous_revision_uuid' => $current->uuid],
                );

                return $revision;
            }, 3);
        } finally {
            DB::connection($connection)->statement('SET @invoice_admin_operation = NULL, @invoice_admin_uuid = NULL');
        }
    }

    private function storeOperation(string $correlationUuid, Invoice $invoice, InvoiceRevision $revision): void
    {
        $operation = new InvoiceIssuedRevisionOperation;
        $operation->forceFill([
            'correlation_uuid' => $correlationUuid,
            'invoice_id' => $invoice->id,
            'invoice_revision_id' => $revision->id,
            'created_by_actor' => auth()->id() === null ? null : 'central-user:'.auth()->id(),
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
