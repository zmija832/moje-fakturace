<?php

namespace App\Models\Business;

use App\Enums\InvoiceDocumentType;
use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([])]
class InvoiceDocument extends BusinessModel
{
    use HasServerGeneratedUuid;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Dokument faktury je neměnný.'));
        static::deleting(fn (): never => throw new LogicException('Dokument faktury nelze smazat.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(InvoiceEmailDelivery::class);
    }

    protected function casts(): array
    {
        return ['document_type' => InvoiceDocumentType::class, 'size_bytes' => 'integer', 'generated_at' => 'immutable_datetime'];
    }
}
