<?php

namespace App\Models\Business;

use App\Enums\DefaultPaymentMethod;
use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([])]
class Invoice extends BusinessModel
{
    use HasServerGeneratedUuid;

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_revision_id', 'current_revision_id')->orderBy('position');
    }

    public function supplierSnapshot(): HasOne
    {
        return $this->hasOne(InvoiceSupplierSnapshot::class, 'invoice_revision_id', 'current_revision_id');
    }

    public function customerSnapshot(): HasOne
    {
        return $this->hasOne(InvoiceCustomerSnapshot::class, 'invoice_revision_id', 'current_revision_id');
    }

    public function bankAccountSnapshot(): HasOne
    {
        return $this->hasOne(InvoiceBankAccountSnapshot::class, 'invoice_revision_id', 'current_revision_id');
    }

    public function vatSnapshots(): HasMany
    {
        return $this->hasMany(InvoiceVatSnapshot::class, 'invoice_revision_id', 'current_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(InvoiceRevision::class)->orderBy('revision_number');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(InvoiceRevision::class, 'current_revision_id');
    }

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => InvoiceStatus::class,
            'issued_on' => 'date',
            'taxable_supply_on' => 'date',
            'due_on' => 'date',
            'payment_method' => DefaultPaymentMethod::class,
            'version' => 'integer',
        ];
    }
}
