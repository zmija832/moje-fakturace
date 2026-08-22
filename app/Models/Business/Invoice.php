<?php

namespace App\Models\Business;

use App\Domain\Invoices\Exceptions\InvoiceIssuedImmutable;
use App\Domain\Invoices\InvoicePaymentSummary;
use App\Enums\DefaultPaymentMethod;
use App\Enums\DocumentType;
use App\Enums\InvoiceDisplayState;
use App\Enums\InvoicePaymentStatus;
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

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            $original = InvoiceStatus::tryFrom((string) $invoice->getRawOriginal('status'));
            if ($original?->hasIssuedDocument() || $invoice->status->hasIssuedDocument()) {
                throw InvoiceIssuedImmutable::mutationDenied();
            }
        });
        static::deleting(function (self $invoice): void {
            if ($invoice->status->hasIssuedDocument()) {
                throw InvoiceIssuedImmutable::mutationDenied();
            }
        });
    }

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

    public function issuedRevision(): BelongsTo
    {
        return $this->belongsTo(InvoiceRevision::class, 'issued_revision_id');
    }

    public function numberAllocation(): BelongsTo
    {
        return $this->belongsTo(DocumentNumberAllocation::class, 'document_number_allocation_id');
    }

    public function documentSequence(): BelongsTo
    {
        return $this->belongsTo(DocumentSequence::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(InvoiceDocument::class)->latest('generated_at')->latest('id');
    }

    public function currentPdfDocument(): ?InvoiceDocument
    {
        return $this->documents->firstWhere('invoice_revision_id', $this->issued_revision_id);
    }

    public function emailDeliveries(): HasMany
    {
        return $this->hasMany(InvoiceEmailDelivery::class)->latest('attempted_at')->latest('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->oldest('paid_on')->oldest('id');
    }

    public function publicLinks(): HasMany
    {
        return $this->hasMany(InvoicePublicLink::class)->latest('id');
    }

    public function customerViewedPublicLink(): ?InvoicePublicLink
    {
        return $this->publicLinks
            ->whereNotNull('first_viewed_at')
            ->sortByDesc('last_viewed_at')
            ->first();
    }

    public function reminderOverride(): HasOne
    {
        return $this->hasOne(InvoiceReminderOverride::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(InvoiceReminder::class)->orderBy('level');
    }

    public function displayState(?InvoicePaymentSummary $paymentSummary = null): InvoiceDisplayState
    {
        if ($this->archived_at !== null) {
            return InvoiceDisplayState::Archived;
        }
        if ($this->status === InvoiceStatus::Cancelled) {
            return InvoiceDisplayState::Cancelled;
        }
        if ($this->status === InvoiceStatus::Draft) {
            return InvoiceDisplayState::Draft;
        }
        if ($paymentSummary?->isOverdue) {
            return InvoiceDisplayState::Overdue;
        }

        return $this->paymentDisplayState($paymentSummary);
    }

    public function paymentDisplayState(?InvoicePaymentSummary $paymentSummary): InvoiceDisplayState
    {
        return match ($paymentSummary?->status) {
            InvoicePaymentStatus::Unpaid => InvoiceDisplayState::Unpaid,
            InvoicePaymentStatus::PartiallyPaid => InvoiceDisplayState::PartiallyPaid,
            InvoicePaymentStatus::Paid => InvoiceDisplayState::Paid,
            InvoicePaymentStatus::Overpaid => InvoiceDisplayState::Overpaid,
            default => InvoiceDisplayState::Unpaid,
        };
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
            'issued_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
