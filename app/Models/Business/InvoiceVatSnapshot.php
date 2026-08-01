<?php

namespace App\Models\Business;

use App\Enums\VatTaxType;
use App\Models\Business\Concerns\ImmutableInvoiceSnapshot;
use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceVatSnapshot extends BusinessModel
{
    use HasServerGeneratedUuid;
    use ImmutableInvoiceSnapshot;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_vat_snapshot_id');
    }

    protected function casts(): array
    {
        return ['tax_type' => VatTaxType::class, 'percentage' => 'decimal:4'];
    }
}
