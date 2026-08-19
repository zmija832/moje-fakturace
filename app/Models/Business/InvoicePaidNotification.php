<?php

namespace App\Models\Business;

use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class InvoicePaidNotification extends BusinessModel
{
    use HasServerGeneratedUuid;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function casts(): array
    {
        return [
            'claimed_at' => 'immutable_datetime',
            'send_attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
