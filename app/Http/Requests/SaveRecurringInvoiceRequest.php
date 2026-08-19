<?php

namespace App\Http\Requests;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Domain\Invoices\InvoiceDecimal;
use App\Enums\DefaultPaymentMethod;
use App\Enums\InvoiceDiscountType;
use App\Enums\VatTaxType;
use App\Models\Business\BankAccount;
use App\Models\Business\Client;
use App\Models\Business\RecurringInvoiceTemplate;
use App\Models\Business\VatRate;
use App\Services\Business\InvoiceVatResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveRecurringInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->isMethod('post') ? 'create' : 'update', RecurringInvoiceTemplate::class);
    }

    public function rules(): array
    {
        $payer = app(InvoiceVatResolver::class)->isVatPayer();

        return [
            'name' => ['required', 'string', 'max:160'],
            'client_uuid' => ['required', 'uuid'],
            'bank_account_uuid' => ['nullable', 'uuid'],
            'currency' => ['required', Rule::in(array_keys(CompanySettingOptions::CURRENCIES))],
            'payment_method' => ['required', Rule::enum(DefaultPaymentMethod::class)],
            'due_days' => ['required', 'integer', 'between:0,365'],
            'interval_months' => ['required', 'integer', Rule::in([1, 3, 6, 12])],
            'next_run_on' => ['required', 'date_format:Y-m-d'],
            'mode' => ['required', Rule::in(['draft', 'auto_issue'])],
            'auto_send' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:5000'],
            'invoice_discount_type' => ['nullable', Rule::enum(InvoiceDiscountType::class)],
            'invoice_discount_value' => ['nullable', 'regex:/^\d{1,15}([\.,]\d{1,4})?$/'],
            'items' => ['required', 'array', 'between:1,500'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'regex:/^\d{1,12}([\.,]\d{1,6})?$/'],
            'items.*.unit' => ['nullable', 'string', 'max:32'],
            'items.*.unit_price' => ['required', 'regex:/^\d{1,15}([\.,]\d{1,4})?$/'],
            'items.*.discount_type' => ['nullable', Rule::enum(InvoiceDiscountType::class)],
            'items.*.discount_value' => ['nullable', 'regex:/^\d{1,15}([\.,]\d{1,4})?$/'],
            'items.*.vat_rate_uuid' => [Rule::requiredIf($payer), Rule::prohibitedIf(! $payer), 'uuid'],
            'business_id' => ['prohibited'],
            'connection' => ['prohibited'],
            'is_active' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! Client::query()->where('uuid', $this->input('client_uuid'))->where('is_active', true)->whereNull('archived_at')->exists()) {
                $validator->errors()->add('client_uuid', 'Vyberte aktivního klienta.');
            }
            if ($this->input('payment_method') === DefaultPaymentMethod::BankTransfer->value && ! $this->filled('bank_account_uuid')) {
                $validator->errors()->add('bank_account_uuid', 'Pro bankovní převod vyberte bankovní účet.');
            }
            if ($this->filled('bank_account_uuid') && ! BankAccount::query()->where('uuid', $this->input('bank_account_uuid'))->where('is_active', true)->whereNull('archived_at')->exists()) {
                $validator->errors()->add('bank_account_uuid', 'Vyberte aktivní bankovní účet.');
            }
            if ($this->boolean('auto_send') && $this->input('mode') !== 'auto_issue') {
                $validator->errors()->add('auto_send', 'Automatické odeslání vyžaduje automatické vystavení.');
            }

            $payer = app(InvoiceVatResolver::class)->isVatPayer();
            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $this->validateDiscount($validator, $item, "items.{$index}.discount_value");
                if ($payer && ! VatRate::query()->where('uuid', $item['vat_rate_uuid'] ?? null)
                    ->where('is_active', true)->whereNull('archived_at')
                    ->where('tax_type', '!=', VatTaxType::NonPayer->value)
                    ->whereDate('valid_from', '<=', $this->input('next_run_on'))
                    ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $this->input('next_run_on')))
                    ->exists()) {
                    $validator->errors()->add("items.{$index}.vat_rate_uuid", 'Vyberte platnou sazbu DPH aktivního subjektu.');
                }
            }
            try {
                $type = (string) $this->input('invoice_discount_type', InvoiceDiscountType::None->value);
                if ($type === InvoiceDiscountType::Percentage->value) {
                    InvoiceDecimal::percentage((string) $this->input('invoice_discount_value'));
                } elseif ($type === InvoiceDiscountType::Fixed->value) {
                    InvoiceDecimal::money((string) $this->input('invoice_discount_value'));
                }
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('invoice_discount_value', $exception->getMessage());
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $items = array_map(static function ($item) {
            if (! is_array($item)) {
                return $item;
            }
            foreach (['quantity', 'unit_price', 'discount_value'] as $field) {
                if (is_scalar($item[$field] ?? null)) {
                    $item[$field] = str_replace(',', '.', trim((string) $item[$field]));
                }
            }
            $item['discount_type'] ??= InvoiceDiscountType::None->value;

            return $item;
        }, (array) $this->input('items', []));

        $this->merge([
            'auto_send' => $this->boolean('auto_send'),
            'items' => $items,
            'invoice_discount_type' => $this->input('invoice_discount_type', InvoiceDiscountType::None->value),
            'invoice_discount_value' => $this->filled('invoice_discount_value') ? str_replace(',', '.', (string) $this->input('invoice_discount_value')) : null,
        ]);
    }

    /** @param array<string,mixed> $item */
    private function validateDiscount(Validator $validator, array $item, string $field): void
    {
        try {
            $type = (string) ($item['discount_type'] ?? InvoiceDiscountType::None->value);
            $value = $item['discount_value'] ?? null;
            if ($type === InvoiceDiscountType::Percentage->value) {
                InvoiceDecimal::percentage((string) $value);
            } elseif ($type === InvoiceDiscountType::Fixed->value) {
                $discount = InvoiceDecimal::money((string) $value);
                $gross = InvoiceDecimal::multiply(InvoiceDecimal::quantity((string) ($item['quantity'] ?? '')), InvoiceDecimal::money((string) ($item['unit_price'] ?? '')));
                if (InvoiceDecimal::compare($discount, $gross) > 0) {
                    throw new \InvalidArgumentException('Pevná sleva nesmí překročit hodnotu položky.');
                }
            }
        } catch (\InvalidArgumentException $exception) {
            $validator->errors()->add($field, $exception->getMessage());
        }
    }
}
