<?php

namespace App\Models\Business;

use App\Models\Business\Concerns\ImmutableInvoiceSnapshot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class InvoiceSupplierSnapshot extends BusinessModel
{
    use ImmutableInvoiceSnapshot;

    public $incrementing = false;

    protected $primaryKey = 'invoice_revision_id';

    protected $keyType = 'int';

    public function revision(): BelongsTo
    {
        return $this->belongsTo(InvoiceRevision::class, 'invoice_revision_id');
    }

    protected function casts(): array
    {
        return ['is_vat_payer' => 'boolean', 'vat_registered_on' => 'date'];
    }
}
