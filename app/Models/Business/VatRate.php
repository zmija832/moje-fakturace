<?php

namespace App\Models\Business;

use App\Enums\VatTaxType;
use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name',
    'code',
    'tax_type',
    'percentage',
    'valid_from',
    'valid_to',
    'is_active',
    'sort_order',
])]
class VatRate extends BusinessModel
{
    use HasServerGeneratedUuid;

    public function defaultAssignment(): HasOne
    {
        return $this->hasOne(VatRateDefault::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    protected function casts(): array
    {
        return [
            'tax_type' => VatTaxType::class,
            'percentage' => 'decimal:4',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
