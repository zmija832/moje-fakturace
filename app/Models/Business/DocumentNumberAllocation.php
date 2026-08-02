<?php

namespace App\Models\Business;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([])]
class DocumentNumberAllocation extends BusinessModel
{
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(DocumentSequence::class, 'document_sequence_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'document_uuid', 'uuid');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Přidělené číslo je neměnný historický záznam.'));
        static::deleting(fn (): never => throw new LogicException('Přidělené číslo nelze smazat.'));
    }

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'sequence_number' => 'integer',
            'allocated_at' => 'immutable_datetime',
        ];
    }
}
