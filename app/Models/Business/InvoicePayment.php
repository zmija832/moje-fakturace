<?php

namespace App\Models\Business;

use App\Domain\Invoices\Exceptions\InvoicePaymentImmutable;
use App\Enums\DefaultPaymentMethod;
use App\Enums\InvoicePaymentSource;
use App\Enums\InvoicePaymentType;
use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class InvoicePayment extends BusinessModel
{
    use HasServerGeneratedUuid;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw InvoicePaymentImmutable::create());
        static::deleting(fn (): never => throw InvoicePaymentImmutable::create());
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_payment_id')->oldest('id');
    }

    protected function casts(): array
    {
        return [
            'payment_type' => InvoicePaymentType::class,
            'amount' => 'decimal:4',
            'paid_on' => 'date',
            'received_at' => 'immutable_datetime',
            'payment_method' => DefaultPaymentMethod::class,
            'source' => InvoicePaymentSource::class,
        ];
    }
}
