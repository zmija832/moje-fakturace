<?php

namespace App\Models\Business;

use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class InvoiceReminder extends BusinessModel
{
    use HasServerGeneratedUuid;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'scheduled_on' => 'date',
            'claimed_at' => 'immutable_datetime',
            'send_attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
