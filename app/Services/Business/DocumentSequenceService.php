<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\DocumentSequences\DocumentNumberFormatter;
use App\Enums\DocumentSequenceResetPeriod;
use App\Enums\DocumentSequenceYearFormat;
use App\Enums\DocumentType;
use App\Models\Business\DocumentSequence;
use App\Models\Business\DocumentSequenceDefault;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentSequenceService
{
    /** @var list<string> */
    private const LOCKED_AFTER_ALLOCATION = [
        'document_type',
        'prefix',
        'suffix',
        'year_format',
        'sequence_digits',
        'start_number',
        'reset_period',
    ];

    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly DocumentNumberFormatter $formatter,
    ) {}

    /** @return Collection<int, DocumentSequence> */
    public function all(): Collection
    {
        return DocumentSequence::query()
            ->with('defaultAssignment')
            ->withCount('allocations')
            ->orderByRaw('archived_at IS NOT NULL')
            ->orderBy('document_type')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function newForForm(): DocumentSequence
    {
        $sequence = new DocumentSequence([
            'document_type' => DocumentType::IssuedInvoice,
            'prefix' => 'FV-',
            'suffix' => '',
            'year_format' => DocumentSequenceYearFormat::FourDigits,
            'sequence_digits' => 5,
            'start_number' => 1,
            'reset_period' => DocumentSequenceResetPeriod::Yearly,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $sequence->forceFill(['next_number' => 1, 'current_period' => null]);

        return $sequence;
    }

    public function find(string $uuid): DocumentSequence
    {
        return DocumentSequence::query()
            ->with('defaultAssignment')
            ->withCount('allocations')
            ->with(['allocations' => fn ($query) => $query->latest('allocated_at')->limit(1)])
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function findForEdit(string $uuid): DocumentSequence
    {
        return DocumentSequence::query()
            ->with('defaultAssignment')
            ->withCount('allocations')
            ->where('uuid', $uuid)
            ->whereNull('archived_at')
            ->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): DocumentSequence
    {
        $this->ensureStartNumberFits($attributes);
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($attributes): DocumentSequence {
            $sequence = new DocumentSequence;
            $sequence->fill($attributes);
            $sequence->forceFill([
                'next_number' => $attributes['start_number'],
                'current_period' => null,
            ]);
            $sequence->save();

            return $sequence->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(string $uuid, array $attributes): DocumentSequence
    {
        $this->ensureStartNumberFits($attributes);
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid, $attributes): DocumentSequence {
            $sequence = $this->lockedSequence($uuid, editableOnly: true);
            $hasAllocations = $sequence->allocations()->exists();

            if ($hasAllocations) {
                $changedFields = array_filter(
                    self::LOCKED_AFTER_ALLOCATION,
                    fn (string $field): bool => array_key_exists($field, $attributes)
                        && (string) $sequence->getRawOriginal($field) !== (string) $attributes[$field],
                );

                if ($changedFields !== []) {
                    throw ValidationException::withMessages([
                        'sequence' => 'Formát použité číselné řady nelze změnit. Pro nový formát vytvořte novou řadu.',
                    ]);
                }
            }

            if (
                array_key_exists('document_type', $attributes)
                && (string) $sequence->getRawOriginal('document_type') !== (string) $attributes['document_type']
                && $sequence->defaultAssignment()->exists()
            ) {
                throw ValidationException::withMessages([
                    'document_type' => 'Typ výchozí řady nelze změnit. Nejprve nastavte jinou výchozí řadu.',
                ]);
            }

            $originalStart = $sequence->start_number;
            $sequence->fill($attributes);

            if (! $hasAllocations && $sequence->start_number !== $originalStart) {
                $sequence->forceFill(['next_number' => $sequence->start_number]);
            }

            $sequence->save();

            if (! $sequence->is_active) {
                $this->removeDefaultAssignment($sequence);
            }

            return $sequence->refresh()->load('defaultAssignment');
        }, 3);
    }

    public function setDefault(string $uuid): DocumentSequence
    {
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid): DocumentSequence {
            $sequence = $this->lockedSequence($uuid);

            if (! $sequence->is_active || $sequence->isArchived()) {
                throw ValidationException::withMessages([
                    'sequence' => 'Výchozí může být pouze aktivní a nearchivovaná číselná řada.',
                ]);
            }

            $documentType = $sequence->document_type->value;
            $assignment = DocumentSequenceDefault::query()
                ->where('document_type', $documentType)
                ->lockForUpdate()
                ->first();

            $assignment ??= new DocumentSequenceDefault;
            $assignment->fill([
                'document_type' => $documentType,
                'document_sequence_id' => $sequence->id,
            ]);
            $assignment->save();

            return $sequence->refresh()->load('defaultAssignment');
        }, 3);
    }

    public function deactivate(string $uuid): DocumentSequence
    {
        return $this->changeActiveState($uuid, false);
    }

    public function activate(string $uuid): DocumentSequence
    {
        return $this->changeActiveState($uuid, true);
    }

    public function archive(string $uuid): DocumentSequence
    {
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid): DocumentSequence {
            $sequence = $this->lockedSequence($uuid, editableOnly: true);
            $sequence->forceFill([
                'is_active' => false,
                'archived_at' => now(),
            ])->save();
            $this->removeDefaultAssignment($sequence);

            return $sequence->refresh()->load('defaultAssignment');
        }, 3);
    }

    public function preview(string $uuid, DateTimeInterface $documentDate): string
    {
        $sequence = DocumentSequence::query()->where('uuid', $uuid)->firstOrFail();
        $number = $this->nextNumberForPeriod($sequence, $documentDate);

        return $this->formatter->format($sequence, $number, $documentDate);
    }

    public function previewModel(DocumentSequence $sequence, DateTimeInterface $documentDate): string
    {
        return $this->formatter->format(
            $sequence,
            $this->nextNumberForPeriod($sequence, $documentDate),
            $documentDate,
        );
    }

    public function nextNumberForPeriod(DocumentSequence $sequence, DateTimeInterface $documentDate): int
    {
        if ($sequence->reset_period === DocumentSequenceResetPeriod::Never) {
            return $sequence->next_number;
        }

        $period = $this->formatter->period($sequence, $documentDate);

        if ($sequence->current_period === $period) {
            return $sequence->next_number;
        }

        $lastNumber = $sequence->allocations()->where('period', $period)->max('sequence_number');

        return $lastNumber === null ? $sequence->start_number : ((int) $lastNumber) + 1;
    }

    private function changeActiveState(string $uuid, bool $isActive): DocumentSequence
    {
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid, $isActive): DocumentSequence {
            $sequence = $this->lockedSequence($uuid);

            if ($sequence->isArchived()) {
                throw ValidationException::withMessages([
                    'sequence' => 'Archivovanou číselnou řadu nelze aktivovat ani deaktivovat.',
                ]);
            }

            $sequence->is_active = $isActive;
            $sequence->save();

            if (! $isActive) {
                $this->removeDefaultAssignment($sequence);
            }

            return $sequence->refresh()->load('defaultAssignment');
        }, 3);
    }

    private function lockedSequence(string $uuid, bool $editableOnly = false): DocumentSequence
    {
        return DocumentSequence::query()
            ->where('uuid', $uuid)
            ->when($editableOnly, fn ($query) => $query->whereNull('archived_at'))
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function removeDefaultAssignment(DocumentSequence $sequence): void
    {
        DocumentSequenceDefault::query()
            ->where('document_sequence_id', $sequence->id)
            ->delete();
    }

    /** @param array<string, mixed> $attributes */
    private function ensureStartNumberFits(array $attributes): void
    {
        if (! isset($attributes['start_number'], $attributes['sequence_digits'])) {
            return;
        }

        if (strlen((string) $attributes['start_number']) > (int) $attributes['sequence_digits']) {
            throw ValidationException::withMessages([
                'start_number' => 'Počáteční číslo se musí vejít do zvoleného počtu číslic.',
            ]);
        }
    }

    private function connectionName(): string
    {
        return $this->connectionResolver->resolve()->connectionName();
    }
}
