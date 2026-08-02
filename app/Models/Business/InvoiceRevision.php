<?php

namespace App\Models\Business;

use App\Enums\DefaultPaymentMethod;
use App\Enums\InvoiceDiscountType;
use App\Models\Business\Concerns\ImmutableInvoiceSnapshot;
use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([])]
class InvoiceRevision extends BusinessModel
{
    use HasServerGeneratedUuid;
    use ImmutableInvoiceSnapshot;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    public function supplierSnapshot(): HasOne
    {
        return $this->hasOne(InvoiceSupplierSnapshot::class);
    }

    public function customerSnapshot(): HasOne
    {
        return $this->hasOne(InvoiceCustomerSnapshot::class);
    }

    public function bankAccountSnapshot(): HasOne
    {
        return $this->hasOne(InvoiceBankAccountSnapshot::class);
    }

    public function vatSnapshots(): HasMany
    {
        return $this->hasMany(InvoiceVatSnapshot::class);
    }

    public function vatSummaries(): HasMany
    {
        return $this->hasMany(InvoiceVatSummary::class)->orderBy('tax_type')->orderBy('percentage');
    }

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'issued_on' => 'date',
            'taxable_supply_on' => 'date',
            'due_on' => 'date',
            'payment_method' => DefaultPaymentMethod::class,
            'invoice_discount_type' => InvoiceDiscountType::class,
            'invoice_discount_value' => 'decimal:4',
            'invoice_discount_amount' => 'decimal:4',
            'subtotal_before_discount' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_base_total' => 'decimal:4',
            'vat_total' => 'decimal:4',
            'total_before_rounding' => 'decimal:4',
            'rounding_adjustment' => 'decimal:4',
            'grand_total' => 'decimal:4',
        ];
    }
}
