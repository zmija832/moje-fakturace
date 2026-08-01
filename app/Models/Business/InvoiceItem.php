<?php

namespace App\Models\Business;

use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['position', 'description', 'quantity', 'unit', 'unit_price'])]
class InvoiceItem extends BusinessModel
{
    use HasServerGeneratedUuid;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function vatSnapshot(): BelongsTo
    {
        return $this->belongsTo(InvoiceVatSnapshot::class, 'invoice_vat_snapshot_id');
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
        ];
    }
}
