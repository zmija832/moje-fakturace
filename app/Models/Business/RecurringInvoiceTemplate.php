<?php

namespace App\Models\Business;

use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'client_uuid', 'bank_account_uuid', 'currency', 'payment_method', 'due_days', 'interval_months', 'anchor_day', 'next_run_on', 'mode', 'auto_send', 'is_active', 'note', 'invoice_discount_type', 'invoice_discount_value'])]
class RecurringInvoiceTemplate extends BusinessModel
{
    use HasServerGeneratedUuid;

    public function items(): HasMany
    {
        return $this->hasMany(RecurringInvoiceItem::class)->orderBy('position');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(RecurringInvoiceRun::class)->latest('scheduled_on')->latest('id');
    }

    protected function casts(): array
    {
        return ['due_days' => 'integer', 'interval_months' => 'integer', 'anchor_day' => 'integer', 'next_run_on' => 'date', 'last_run_at' => 'immutable_datetime', 'auto_send' => 'boolean', 'is_active' => 'boolean', 'invoice_discount_value' => 'decimal:4', 'version' => 'integer'];
    }
}
