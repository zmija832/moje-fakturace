<?php

namespace App\Models\Business;

use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class BankTransaction extends BusinessModel
{
    use HasServerGeneratedUuid;

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'matched_invoice_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }

    protected function casts(): array
    {
        return [
            'booked_on' => 'date',
            'amount' => 'decimal:4',
            'imported_at' => 'immutable_datetime',
            'matched_at' => 'immutable_datetime',
        ];
    }
}
