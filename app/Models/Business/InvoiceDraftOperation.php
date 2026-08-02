<?php

namespace App\Models\Business;

use App\Models\Business\Concerns\ImmutableInvoiceSnapshot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class InvoiceDraftOperation extends BusinessModel
{
    use ImmutableInvoiceSnapshot;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(InvoiceRevision::class, 'invoice_revision_id');
    }
}
