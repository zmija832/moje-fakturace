<?php

namespace App\Models\Business;

use App\Enums\ClientType;
use App\Models\Business\Concerns\ImmutableInvoiceSnapshot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceCustomerSnapshot extends BusinessModel
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
        return ['client_type' => ClientType::class];
    }
}
