<?php

namespace App\Models\Business;

use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'unit_price', 'unit', 'currency', 'vat_rate_uuid', 'is_active'])]
class InvoiceCatalogItem extends BusinessModel
{
    use HasServerGeneratedUuid;

    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class, 'vat_rate_uuid', 'uuid');
    }

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:4', 'is_active' => 'boolean'];
    }
}
