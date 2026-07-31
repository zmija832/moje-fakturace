<?php

namespace App\Models\Business;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_type',
    'document_sequence_id',
])]
class DocumentSequenceDefault extends BusinessModel
{
    public $incrementing = false;

    protected $primaryKey = 'document_type';

    protected $keyType = 'string';

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(DocumentSequence::class, 'document_sequence_id');
    }

    protected function casts(): array
    {
        return ['document_type' => DocumentType::class];
    }
}
