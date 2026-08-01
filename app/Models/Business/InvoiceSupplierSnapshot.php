<?php

namespace App\Models\Business;

use App\Models\Business\Concerns\ImmutableInvoiceSnapshot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSupplierSnapshot extends BusinessModel
{
    use ImmutableInvoiceSnapshot;

    public $incrementing = false;

    protected $primaryKey = 'invoice_id';

    protected $keyType = 'int';

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function casts(): array
    {
        return ['is_vat_payer' => 'boolean', 'vat_registered_on' => 'date'];
    }
}
