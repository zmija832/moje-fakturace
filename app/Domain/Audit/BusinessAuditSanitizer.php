<?php

namespace App\Domain\Audit;

use App\Enums\BusinessAuditableType;
use App\Models\Business\BankAccount;
use App\Models\Business\Client;
use App\Models\Business\CompanySetting;
use App\Models\Business\DocumentNumberAllocation;
use App\Models\Business\DocumentSequence;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceRevision;
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
            BusinessAuditableType::BankAccount => $this->bankAccount($this->expect($model, BankAccount::class)),
            BusinessAuditableType::Client => $this->client($this->expect($model, Client::class)),
            BusinessAuditableType::DocumentSequence => $this->documentSequence($this->expect($model, DocumentSequence::class)),
            BusinessAuditableType::DocumentNumberAllocation => $this->allocation($this->expect($model, DocumentNumberAllocation::class)),
            BusinessAuditableType::VatRate => $this->vatRate($this->expect($model, VatRate::class)),
            BusinessAuditableType::Invoice => $this->invoice($this->expect($model, Invoice::class)),
            BusinessAuditableType::BankAccountDefault,
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
                'due_on', 'payment_method', 'variable_symbol', 'note',
            ],
            BusinessAuditableType::BankAccountDefault,
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
            ...($revision === null ? [] : $this->invoiceRevision($revision)),
        ]);
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
