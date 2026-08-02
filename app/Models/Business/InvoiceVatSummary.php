<?php

namespace App\Models\Business;

use App\Enums\VatTaxType;
use App\Models\Business\Concerns\ImmutableInvoiceSnapshot;
use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class InvoiceVatSummary extends BusinessModel
{
    use HasServerGeneratedUuid;
    use ImmutableInvoiceSnapshot;

    public function revision(): BelongsTo
    {
        return $this->belongsTo(InvoiceRevision::class, 'invoice_revision_id');
    }

    protected function casts(): array
    {
        return [
            'tax_type' => VatTaxType::class,
            'percentage' => 'decimal:4',
            'tax_base' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
        ];
    }
}
