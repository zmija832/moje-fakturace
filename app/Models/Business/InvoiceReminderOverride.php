<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['disabled', 'updated_by_actor'])]
class InvoiceReminderOverride extends BusinessModel
{
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function casts(): array
    {
        return ['disabled' => 'boolean'];
    }
}
