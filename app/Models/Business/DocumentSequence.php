<?php

namespace App\Models\Business;

use App\Enums\DocumentSequenceResetPeriod;
use App\Enums\DocumentSequenceYearFormat;
use App\Enums\DocumentType;
use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'document_type',
    'name',
    'prefix',
    'suffix',
    'year_format',
    'sequence_digits',
    'start_number',
    'reset_period',
    'is_active',
    'sort_order',
])]
class DocumentSequence extends BusinessModel
{
    use HasServerGeneratedUuid;

    public function defaultAssignment(): HasOne
    {
        return $this->hasOne(DocumentSequenceDefault::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DocumentNumberAllocation::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'year_format' => DocumentSequenceYearFormat::class,
            'reset_period' => DocumentSequenceResetPeriod::class,
            'sequence_digits' => 'integer',
            'start_number' => 'integer',
            'next_number' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
