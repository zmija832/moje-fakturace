<?php

namespace App\Models\Business;

use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class RecurringInvoiceRun extends BusinessModel
{
    use HasServerGeneratedUuid;

    public function template(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoiceTemplate::class, 'recurring_invoice_template_id');
    }

    protected function casts(): array
    {
        return ['scheduled_on' => 'date', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
