<?php

namespace App\Models\Business;

use App\Enums\InvoiceLabelColor;
use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'color', 'is_active'])]
class InvoiceLabel extends BusinessModel
{
    use HasServerGeneratedUuid;

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class)->withTimestamps();
    }

    protected function casts(): array
    {
        return ['color' => InvoiceLabelColor::class, 'is_active' => 'boolean'];
    }
}
