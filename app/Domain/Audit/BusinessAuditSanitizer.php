<?php

namespace App\Domain\Audit;

use App\Enums\BusinessAuditableType;
use App\Models\Business\BankAccount;
use App\Models\Business\Client;
use App\Models\Business\CompanySetting;
use App\Models\Business\DocumentNumberAllocation;
use App\Models\Business\DocumentSequence;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceAutomationSetting;
use App\Models\Business\InvoiceCatalogItem;
use App\Models\Business\InvoiceDocument;
use App\Models\Business\InvoiceEmailDelivery;
use App\Models\Business\InvoiceEmailSetting;
use App\Models\Business\InvoicePayment;
use App\Models\Business\InvoicePublicLink;
use App\Models\Business\InvoiceRevision;
use App\Models\Business\RecurringInvoiceTemplate;
use App\Models\Business\VatRate;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class BusinessAuditSanitizer
{
    /** @return array<string, mixed> */
    public function snapshot(BusinessAuditableType $type, Model $model): array
    {
        return match ($type) {
            BusinessAuditableType::CompanySettings => $this->companySettings($this->expect($model, CompanySetting::class)),
            BusinessAuditableType::InvoiceEmailSettings => $this->invoiceEmailSettings($this->expect($model, InvoiceEmailSetting::class)),
            BusinessAuditableType::BankAccount => $this->bankAccount($this->expect($model, BankAccount::class)),
            BusinessAuditableType::Client => $this->client($this->expect($model, Client::class)),
            BusinessAuditableType::InvoiceCatalogItem => $this->catalogItem($this->expect($model, InvoiceCatalogItem::class)),
            BusinessAuditableType::DocumentSequence => $this->documentSequence($this->expect($model, DocumentSequence::class)),
            BusinessAuditableType::DocumentNumberAllocation => $this->allocation($this->expect($model, DocumentNumberAllocation::class)),
            BusinessAuditableType::VatRate => $this->vatRate($this->expect($model, VatRate::class)),
            BusinessAuditableType::Invoice => $this->invoice($this->expect($model, Invoice::class)),
            BusinessAuditableType::InvoiceDocument => $this->invoiceDocument($this->expect($model, InvoiceDocument::class)),
            BusinessAuditableType::InvoiceEmailDelivery => $this->invoiceEmailDelivery($this->expect($model, InvoiceEmailDelivery::class)),
            BusinessAuditableType::InvoicePayment => $this->invoicePayment($this->expect($model, InvoicePayment::class)),
            BusinessAuditableType::InvoicePublicLink => $this->invoicePublicLink($this->expect($model, InvoicePublicLink::class)),
            BusinessAuditableType::RecurringInvoice => $this->recurringInvoice($this->expect($model, RecurringInvoiceTemplate::class)),
            BusinessAuditableType::InvoiceAutomationSettings => $this->automationSettings($this->expect($model, InvoiceAutomationSetting::class)),
            BusinessAuditableType::BankAccountDefault,
            BusinessAuditableType::FioBankAccountSetting,
            BusinessAuditableType::BankTransaction,
            BusinessAuditableType::DocumentSequenceDefault,
            BusinessAuditableType::VatRateDefault => throw new InvalidArgumentException('Default vazby používají explicitní bezpečný kontext.'),
        };
    }

    /** @return list<string> */
    public function changedFields(BusinessAuditableType $type, Model $model): array
    {
        $allowed = match ($type) {
            BusinessAuditableType::CompanySettings => [
                'legal_name', 'additional_name', 'registration_number', 'tax_id', 'vat_id',
                'street', 'house_number', 'orientation_number', 'city', 'postal_code',
                'country_code', 'email', 'phone', 'website', 'default_currency',
                'document_locale', 'timezone', 'is_vat_payer', 'vat_registered_on',
                'default_due_days', 'default_payment_method', 'invoice_intro', 'invoice_outro',
            ],
            BusinessAuditableType::InvoiceEmailSettings => [
                'sender_name', 'reply_to', 'subject_template', 'body_template', 'signature',
                'attach_pdf', 'include_web_invoice',
            ],
            BusinessAuditableType::BankAccount => [
                'name', 'domestic_prefix', 'domestic_account_number', 'bank_code', 'iban',
                'bic', 'currency', 'is_active', 'sort_order', 'note', 'archived_at',
            ],
            BusinessAuditableType::Client => [
                'type', 'display_name', 'company_name', 'first_name', 'last_name',
                'registration_number', 'tax_id', 'vat_id', 'email', 'phone', 'website',
                'contact_person', 'street', 'house_number', 'orientation_number', 'city',
                'postal_code', 'country_code', 'delivery_name', 'delivery_street',
                'delivery_house_number', 'delivery_orientation_number', 'delivery_city',
                'delivery_postal_code', 'delivery_country_code', 'default_currency',
                'default_due_days', 'default_payment_method', 'language', 'note',
                'is_active', 'archived_at',
            ],
            BusinessAuditableType::InvoiceCatalogItem => ['name', 'unit_price', 'unit', 'currency', 'vat_rate_uuid', 'is_active'],
            BusinessAuditableType::DocumentSequence => [
                'document_type', 'name', 'prefix', 'suffix', 'year_format',
                'sequence_digits', 'start_number', 'reset_period', 'is_active',
                'sort_order', 'archived_at',
            ],
            BusinessAuditableType::DocumentNumberAllocation => [
                'correlation_uuid', 'document_sequence_id', 'document_type', 'period',
                'sequence_number', 'formatted_number', 'allocated_at', 'document_uuid',
            ],
            BusinessAuditableType::VatRate => [
                'name', 'code', 'tax_type', 'percentage', 'valid_from', 'valid_to',
                'is_active', 'sort_order', 'archived_at',
            ],
            BusinessAuditableType::Invoice => [
                'document_type', 'status', 'currency', 'issued_on', 'taxable_supply_on',
                'due_on', 'payment_method', 'variable_symbol', 'note', 'archived_at',
                'cancelled_at', 'cancelled_by_actor', 'cancellation_reason',
            ],
            BusinessAuditableType::InvoiceDocument,
            BusinessAuditableType::InvoiceEmailDelivery,
            BusinessAuditableType::InvoicePayment => [],
            BusinessAuditableType::InvoicePublicLink => [],
            BusinessAuditableType::RecurringInvoice => ['name', 'client_uuid', 'bank_account_uuid', 'currency', 'payment_method', 'due_days', 'interval_months', 'next_run_on', 'mode', 'auto_send', 'is_active', 'note', 'invoice_discount_type', 'invoice_discount_value'],
            BusinessAuditableType::InvoiceAutomationSettings => ['reminders_enabled', 'reminder_mode', 'reminder_day_1', 'reminder_day_2', 'reminder_day_3', 'notify_admin_when_paid', 'notify_customer_when_paid', 'paid_subject', 'paid_body'],
            BusinessAuditableType::BankAccountDefault,
            BusinessAuditableType::FioBankAccountSetting,
            BusinessAuditableType::BankTransaction,
            BusinessAuditableType::DocumentSequenceDefault,
            BusinessAuditableType::VatRateDefault => [],
        };

        return array_values(array_intersect(array_keys($model->getDirty()), $allowed));
    }

    /** @return array<string, mixed> */
    public function invoiceRevision(InvoiceRevision $revision): array
    {
        $revision->loadMissing(['items', 'vatSummaries', 'bankAccountSnapshot']);

        return $this->withoutNulls([
            'revision_uuid' => $revision->uuid,
            'revision_number' => $revision->revision_number,
            'currency' => $revision->currency,
            'issued_on' => $this->scalar($revision->issued_on),
            'taxable_supply_on' => $this->scalar($revision->taxable_supply_on),
            'due_on' => $this->scalar($revision->due_on),
            'payment_method' => $this->scalar($revision->payment_method),
            'item_count' => $revision->items->count(),
            'vat_summary_count' => $revision->vatSummaries->count(),
            'has_bank_account' => $revision->bankAccountSnapshot !== null,
            'invoice_discount_type' => $this->scalar($revision->invoice_discount_type),
            'invoice_discount_value' => $revision->invoice_discount_value,
            'invoice_discount_amount' => $revision->invoice_discount_amount,
            'subtotal_before_discount' => $revision->subtotal_before_discount,
            'discount_total' => $revision->discount_total,
            'tax_base_total' => $revision->tax_base_total,
            'vat_total' => $revision->vat_total,
            'total_before_rounding' => $revision->total_before_rounding,
            'rounding_adjustment' => $revision->rounding_adjustment,
            'grand_total' => $revision->grand_total,
        ]);
    }

    /** @return array<string, mixed> */
    public function issuedInvoice(Invoice $invoice, DocumentNumberAllocation $allocation): array
    {
        $invoice->loadMissing('issuedRevision.items', 'issuedRevision.vatSummaries');
        $revision = $invoice->issuedRevision;

        if ($revision === null) {
            throw new InvalidArgumentException('Vystavená faktura nemá uzamknutou revizi.');
        }

        return [
            'invoice_uuid' => $invoice->uuid,
            'document_number' => $invoice->document_number,
            'document_type' => $this->scalar($invoice->document_type),
            'allocation_correlation_uuid' => $allocation->correlation_uuid,
            'issued_revision_uuid' => $revision->uuid,
            'version' => $invoice->version,
            'archived_at' => $this->scalar($invoice->archived_at),
            'cancelled_at' => $this->scalar($invoice->cancelled_at),
            'cancelled_by_actor' => $invoice->cancelled_by_actor,
            'cancellation_reason' => $invoice->cancellation_reason,
            'issued_on' => $this->scalar($revision->issued_on),
            'taxable_supply_on' => $this->scalar($revision->taxable_supply_on),
            'due_on' => $this->scalar($revision->due_on),
            'currency' => $revision->currency,
            'payment_method' => $this->scalar($revision->payment_method),
            'subtotal_before_discount' => $revision->subtotal_before_discount,
            'discount_total' => $revision->discount_total,
            'tax_base_total' => $revision->tax_base_total,
            'vat_total' => $revision->vat_total,
            'total_before_rounding' => $revision->total_before_rounding,
            'rounding_adjustment' => $revision->rounding_adjustment,
            'grand_total' => $revision->grand_total,
            'item_count' => $revision->items->count(),
            'vat_summary_count' => $revision->vatSummaries->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function companySettings(CompanySetting $setting): array
    {
        return $this->withoutNulls([
            'legal_name' => $setting->legal_name,
            'additional_name' => $setting->additional_name,
            'registration_number' => $setting->registration_number,
            'tax_id_masked' => $this->maskLastFour($setting->tax_id),
            'vat_id_masked' => $this->maskLastFour($setting->vat_id),
            'city' => $setting->city,
            'country_code' => $setting->country_code,
            'default_currency' => $setting->default_currency,
            'document_locale' => $setting->document_locale,
            'timezone' => $setting->timezone,
            'is_vat_payer' => (bool) $setting->is_vat_payer,
            'vat_registered_on' => $this->scalar($setting->vat_registered_on),
            'default_due_days' => $setting->default_due_days,
            'default_payment_method' => $this->scalar($setting->default_payment_method),
        ]);
    }

    /** @return array<string, mixed> */
    private function invoiceEmailSettings(InvoiceEmailSetting $setting): array
    {
        return [
            'sender_name' => $setting->sender_name,
            'reply_to_configured' => filter_var($setting->reply_to, FILTER_VALIDATE_EMAIL) !== false,
            'subject_template' => $setting->subject_template,
            'body_template' => $setting->body_template,
            'signature' => $setting->signature,
            'attach_pdf' => (bool) $setting->attach_pdf,
            'include_web_invoice' => (bool) $setting->include_web_invoice,
        ];
    }

    /** @return array<string, mixed> */
    private function bankAccount(BankAccount $account): array
    {
        return $this->withoutNulls([
            'name' => $account->name,
            'currency' => $account->currency,
            'is_active' => (bool) $account->is_active,
            'is_archived' => $account->archived_at !== null,
            'sort_order' => $account->sort_order,
            'domestic_account_masked' => $this->maskLastFour($account->domestic_account_number),
            'iban_masked' => $this->maskLastFour($account->iban),
        ]);
    }

    /** @return array<string, mixed> */
    private function client(Client $client): array
    {
        return $this->withoutNulls([
            'type' => $this->scalar($client->type),
            'display_name' => $client->display_name,
            'company_name' => $client->company_name,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'registration_number_masked' => $this->maskLastFour($client->registration_number),
            'city' => $client->city,
            'country_code' => $client->country_code,
            'default_currency' => $client->default_currency,
            'is_active' => (bool) $client->is_active,
            'is_archived' => $client->archived_at !== null,
        ]);
    }

    /** @return array<string, mixed> */
    private function catalogItem(InvoiceCatalogItem $item): array
    {
        return $this->withoutNulls([
            'name' => $item->name,
            'unit_price' => $item->unit_price,
            'unit' => $item->unit,
            'currency' => $item->currency,
            'vat_rate_uuid' => $item->vat_rate_uuid,
            'is_active' => (bool) $item->is_active,
        ]);
    }

    /** @return array<string, mixed> */
    private function documentSequence(DocumentSequence $sequence): array
    {
        return $this->withoutNulls([
            'document_type' => $this->scalar($sequence->document_type),
            'name' => $sequence->name,
            'prefix' => $sequence->prefix,
            'suffix' => $sequence->suffix,
            'year_format' => $this->scalar($sequence->year_format),
            'sequence_digits' => $sequence->sequence_digits,
            'start_number' => $sequence->start_number,
            'reset_period' => $this->scalar($sequence->reset_period),
            'is_active' => (bool) $sequence->is_active,
            'is_archived' => $sequence->archived_at !== null,
            'sort_order' => $sequence->sort_order,
        ]);
    }

    /** @return array<string, mixed> */
    private function allocation(DocumentNumberAllocation $allocation): array
    {
        return $this->withoutNulls([
            'document_type' => $this->scalar($allocation->document_type),
            'sequence_uuid' => $allocation->sequence?->uuid,
            'period' => $allocation->period,
            'sequence_number' => $allocation->sequence_number,
            'formatted_number' => $allocation->formatted_number,
            'correlation_uuid' => $allocation->correlation_uuid,
            'document_uuid' => $allocation->document_uuid,
        ]);
    }

    /** @return array<string, mixed> */
    private function vatRate(VatRate $rate): array
    {
        return $this->withoutNulls([
            'name' => $rate->name,
            'code' => $rate->code,
            'tax_type' => $this->scalar($rate->tax_type),
            'percentage' => $rate->percentage,
            'valid_from' => $this->scalar($rate->valid_from),
            'valid_to' => $this->scalar($rate->valid_to),
            'is_active' => (bool) $rate->is_active,
            'is_archived' => $rate->archived_at !== null,
            'sort_order' => $rate->sort_order,
        ]);
    }

    /** @return array<string, mixed> */
    private function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing('currentRevision');
        $revision = $invoice->currentRevision;

        return $this->withoutNulls([
            'document_type' => $this->scalar($invoice->document_type),
            'status' => $this->scalar($invoice->status),
            'version' => $invoice->version,
            'archived_at' => $this->scalar($invoice->archived_at),
            ...($revision === null ? [] : $this->invoiceRevision($revision)),
        ]);
    }

    /** @return array<string, mixed> */
    private function invoiceDocument(InvoiceDocument $document): array
    {
        return [
            'document_uuid' => $document->uuid,
            'invoice_uuid' => $document->invoice?->uuid,
            'document_number' => $document->invoice?->document_number,
            'filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'sha256' => $document->sha256,
            'template_version' => $document->template_version,
            'correlation_uuid' => $document->generation_correlation_uuid,
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceEmailDelivery(InvoiceEmailDelivery $delivery): array
    {
        return $this->withoutNulls([
            'delivery_uuid' => $delivery->uuid,
            'invoice_uuid' => $delivery->invoice?->uuid,
            'document_uuid' => $delivery->document?->uuid,
            'recipient_email_masked' => $this->maskEmail($delivery->recipient_email),
            'status' => $this->scalar($delivery->status),
            'subject' => mb_substr($delivery->subject, 0, 160),
            'correlation_uuid' => $delivery->send_correlation_uuid,
            'failure_code' => $delivery->failure_code,
        ]);
    }

    /** @return array<string, mixed> */
    private function invoicePayment(InvoicePayment $payment): array
    {
        $payment->loadMissing(['invoice', 'originalPayment']);

        return $this->withoutNulls([
            'invoice_uuid' => $payment->invoice?->uuid,
            'document_number' => $payment->invoice?->document_number,
            'payment_uuid' => $payment->uuid,
            'payment_type' => $this->scalar($payment->payment_type),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'paid_on' => $this->scalar($payment->paid_on),
            'payment_method' => $this->scalar($payment->payment_method),
            'reference_masked' => $this->maskLastFour($payment->reference),
            'variable_symbol_masked' => $this->maskLastFour($payment->variable_symbol),
            'source' => $this->scalar($payment->source),
            'correlation_uuid' => $payment->correlation_uuid,
            'reverses_payment_uuid' => $payment->originalPayment?->uuid,
        ]);
    }

    /** @return array<string, mixed> */
    private function invoicePublicLink(InvoicePublicLink $link): array
    {
        $link->loadMissing('invoice');

        return $this->withoutNulls([
            'link_uuid' => $link->uuid,
            'invoice_uuid' => $link->invoice?->uuid,
            'active' => $link->revoked_at === null,
            'created_at' => $this->scalar($link->created_at),
            'revoked_at' => $this->scalar($link->revoked_at),
        ]);
    }

    private function recurringInvoice(RecurringInvoiceTemplate $template): array
    {
        return $this->withoutNulls(['name' => $template->name, 'client_uuid' => $template->client_uuid, 'bank_account_uuid' => $template->bank_account_uuid, 'currency' => $template->currency, 'payment_method' => $template->payment_method, 'due_days' => $template->due_days, 'interval_months' => $template->interval_months, 'next_run_on' => $this->scalar($template->next_run_on), 'mode' => $template->mode, 'auto_send' => (bool) $template->auto_send, 'is_active' => (bool) $template->is_active, 'version' => $template->version]);
    }

    private function automationSettings(InvoiceAutomationSetting $setting): array
    {
        return ['reminders_enabled' => (bool) $setting->reminders_enabled, 'reminder_mode' => $setting->reminder_mode, 'reminder_day_1' => $setting->reminder_day_1, 'reminder_day_2' => $setting->reminder_day_2, 'reminder_day_3' => $setting->reminder_day_3, 'notify_admin_when_paid' => (bool) $setting->notify_admin_when_paid, 'notify_customer_when_paid' => (bool) $setting->notify_customer_when_paid];
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    private function maskLastFour(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return '••••'.mb_substr((string) $value, -4);
    }

    private function scalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => $value,
        };
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function withoutNulls(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null);
    }

    /** @template T of Model @param class-string<T> $class @return T */
    private function expect(Model $model, string $class): Model
    {
        if (! $model instanceof $class) {
            throw new InvalidArgumentException("Auditovaný model neodpovídá typu {$class}.");
        }

        return $model;
    }
}
