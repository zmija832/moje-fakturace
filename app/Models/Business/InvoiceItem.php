<?php

namespace App\Models\Business;

use App\Enums\InvoiceDiscountType;
use App\Models\Business\Concerns\ImmutableInvoiceSnapshot;
use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class InvoiceItem extends BusinessModel
{
    use HasServerGeneratedUuid;
    use ImmutableInvoiceSnapshot;

    public function revision(): BelongsTo
    {
        return $this->belongsTo(InvoiceRevision::class, 'invoice_revision_id');
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
            'discount_type' => InvoiceDiscountType::class,
            'discount_value' => 'decimal:4',
            'line_discount_amount' => 'decimal:4',
            'invoice_discount_amount' => 'decimal:4',
            'unit_price_after_discount' => 'decimal:4',
            'line_net_amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'line_total_amount' => 'decimal:4',
        ];
    }
}
