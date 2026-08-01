<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\DocumentSequences\DocumentNumberFormatter;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Models\Business\DocumentNumberAllocation;
use App\Models\Business\DocumentSequence;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentNumberAllocator
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly DocumentNumberFormatter $formatter,
        private readonly DocumentSequenceService $sequenceService,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    public function allocate(
        string $sequenceUuid,
        DateTimeInterface $documentDate,
        ?string $correlationUuid = null,
    ): DocumentNumberAllocation {
        $correlationUuid ??= (string) Str::uuid();

        if (! Str::isUuid($correlationUuid)) {
            throw ValidationException::withMessages([
                'correlation_uuid' => 'Idempotency klíč musí být platné UUID.',
            ]);
        }

        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use (
            $sequenceUuid,
            $documentDate,
            $correlationUuid,
        ): DocumentNumberAllocation {
            $sequence = DocumentSequence::query()
                ->where('uuid', $sequenceUuid)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = DocumentNumberAllocation::query()
                ->where('correlation_uuid', $correlationUuid)
                ->first();

            if ($existing !== null) {
                if (
                    $existing->document_sequence_id !== $sequence->id
                    || $existing->document_type !== $sequence->document_type
                ) {
                    throw ValidationException::withMessages([
                        'correlation_uuid' => 'Idempotency klíč již patří jiné číselné řadě.',
                    ]);
                }

                return $existing;
            }

            if (! $sequence->is_active || $sequence->isArchived()) {
                throw ValidationException::withMessages([
                    'sequence' => 'Číslo lze přidělit pouze z aktivní a nearchivované řady.',
                ]);
            }

            $period = $this->formatter->period($sequence, $documentDate);
            $sequenceNumber = $this->sequenceService->nextNumberForPeriod($sequence, $documentDate);
            $formattedNumber = $this->formatter->format($sequence, $sequenceNumber, $documentDate);

            $allocation = new DocumentNumberAllocation;
            $allocation->forceFill([
                'correlation_uuid' => $correlationUuid,
                'document_sequence_id' => $sequence->id,
                'document_type' => $sequence->document_type->value,
                'period' => $period,
                'sequence_number' => $sequenceNumber,
                'formatted_number' => $formattedNumber,
                'allocated_at' => now(),
                'document_uuid' => null,
            ]);
            $allocation->save();

            $sequence->forceFill([
                'next_number' => $sequenceNumber + 1,
                'current_period' => $period === 'never' ? null : $period,
            ])->save();

            $allocation->setRelation('sequence', $sequence);
            $snapshot = $this->auditSanitizer->snapshot(BusinessAuditableType::DocumentNumberAllocation, $allocation);
            $this->auditWriter->write(
                BusinessAuditEvent::DocumentNumberAllocated,
                BusinessAuditableType::DocumentNumberAllocation,
                $allocation->correlation_uuid,
                null,
                $snapshot,
                array_keys($snapshot),
                BusinessAuditableType::DocumentSequence,
                $sequence->uuid,
            );

            return $allocation->refresh();
        }, 3);
    }
}
