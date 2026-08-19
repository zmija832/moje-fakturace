<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceReminderOverride;
use Illuminate\Support\Facades\DB;

class InvoiceReminderPreferenceService
{
    public function __construct(private readonly BusinessConnectionResolver $resolver, private readonly BusinessAuditWriter $audit) {}

    public function set(Invoice $invoice, bool $disabled, ?string $actor): InvoiceReminderOverride
    {
        $connection = $this->resolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($invoice, $disabled, $actor): InvoiceReminderOverride {
            $override = InvoiceReminderOverride::query()->where('invoice_id', $invoice->id)->lockForUpdate()->first();
            $before = ['disabled' => (bool) $override?->disabled];
            $override ??= new InvoiceReminderOverride(['invoice_id' => $invoice->id]);
            $override->forceFill(['invoice_id' => $invoice->id, 'disabled' => $disabled, 'updated_by_actor' => $actor])->save();
            $this->audit->write(BusinessAuditEvent::InvoiceReminderPreferenceChanged, BusinessAuditableType::Invoice, $invoice->uuid, $before, ['disabled' => $disabled], ['reminders_disabled']);

            return $override;
        }, 3);
    }
}
