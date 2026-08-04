<?php

namespace App\Models\Business;

use App\Enums\InvoiceEmailDeliveryStatus;
use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([])]
class InvoiceEmailDelivery extends BusinessModel
{
    use HasServerGeneratedUuid;

    protected static function booted(): void
    {
        static::updating(function (self $delivery): void {
            $allowed = ['status', 'provider_message_id', 'sent_at', 'failed_at', 'failure_code', 'failure_message', 'updated_at'];
            if (array_diff(array_keys($delivery->getDirty()), $allowed) !== [] || $delivery->getRawOriginal('status') !== InvoiceEmailDeliveryStatus::Pending->value) {
                throw new LogicException('Obsah historie odeslání je neměnný.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Historii odeslání nelze smazat.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(InvoiceDocument::class, 'invoice_document_id');
    }

    protected function casts(): array
    {
        return ['status' => InvoiceEmailDeliveryStatus::class, 'attempted_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime'];
    }
}
