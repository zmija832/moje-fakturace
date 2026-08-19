<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['position', 'description', 'quantity', 'unit', 'unit_price', 'discount_type', 'discount_value', 'vat_rate_uuid'])]
class RecurringInvoiceItem extends BusinessModel
{
    public function template(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoiceTemplate::class, 'recurring_invoice_template_id');
    }

    protected function casts(): array
    {
        return ['position' => 'integer', 'quantity' => 'decimal:6', 'unit_price' => 'decimal:4', 'discount_value' => 'decimal:4'];
    }
}
