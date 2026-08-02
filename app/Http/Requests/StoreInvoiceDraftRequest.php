<?php

namespace App\Http\Requests;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Domain\Invoices\InvoiceDecimal;
use App\Enums\DefaultPaymentMethod;
use App\Enums\InvoiceDiscountType;
use App\Models\Business\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInvoiceDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Invoice::class);
    }

    public function rules(): array
    {
        return [
            'customer_uuid' => ['required', 'uuid'],
            'bank_account_uuid' => ['nullable', 'uuid'],
            'currency' => ['required', Rule::in(array_keys(CompanySettingOptions::CURRENCIES))],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'taxable_supply_on' => ['required', 'date_format:Y-m-d'],
            'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'payment_method' => ['required', Rule::enum(DefaultPaymentMethod::class)],
            'variable_symbol' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'note' => ['nullable', 'string', 'max:5000'],
            'invoice_discount_type' => ['nullable', Rule::enum(InvoiceDiscountType::class)],
            'invoice_discount_value' => ['nullable', 'string', 'max:32'],
            'items' => ['required', 'array', 'between:1,500'],
            'items.*.position' => ['nullable', 'integer', 'min:1', 'max:65535', 'distinct'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'string', 'max:32'],
            'items.*.unit' => ['nullable', 'string', 'max:32'],
            'items.*.unit_price' => ['required', 'string', 'max:32'],
            'items.*.discount_type' => ['nullable', Rule::enum(InvoiceDiscountType::class)],
            'items.*.discount_value' => ['nullable', 'string', 'max:32'],
            'items.*.vat_rate_uuid' => ['required', 'uuid'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'status' => ['prohibited'],
            'document_type' => ['prohibited'],
            'document_number' => ['prohibited'],
            'document_number_allocation_id' => ['prohibited'],
            'allocation_id' => ['prohibited'],
            'issued_at' => ['prohibited'],
            'issued_revision_id' => ['prohibited'],
            'connection' => ['prohibited'],
            'business_id' => ['prohibited'],
            'archived_at' => ['prohibited'],
            'supplier_snapshot' => ['prohibited'],
            'customer_snapshot' => ['prohibited'],
            'bank_account_snapshot' => ['prohibited'],
            'vat_snapshot' => ['prohibited'],
            'version' => ['prohibited'],
            'current_revision_id' => ['prohibited'],
            'subtotal_before_discount' => ['prohibited'],
            'discount_total' => ['prohibited'],
            'invoice_discount_amount' => ['prohibited'],
            'tax_base_total' => ['prohibited'],
            'vat_total' => ['prohibited'],
            'total_before_rounding' => ['prohibited'],
            'rounding_adjustment' => ['prohibited'],
            'grand_total' => ['prohibited'],
            'vat_summaries' => ['prohibited'],
            'totals' => ['prohibited'],
            'snapshots' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'correlation_uuid' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (
                $this->input('payment_method') === DefaultPaymentMethod::BankTransfer->value
                && ! $this->filled('bank_account_uuid')
            ) {
                $validator->errors()->add('bank_account_uuid', 'Pro bankovní převod vyberte bankovní účet.');
            }

            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (['quantity' => 'quantity', 'unit_price' => 'money'] as $field => $method) {
                    if (! is_scalar($item[$field] ?? null)) {
                        continue;
                    }

                    try {
                        InvoiceDecimal::{$method}((string) $item[$field]);
                    } catch (\InvalidArgumentException $exception) {
                        $validator->errors()->add("items.{$index}.{$field}", $exception->getMessage());
                    }
                }

                $discountType = (string) ($item['discount_type'] ?? InvoiceDiscountType::None->value);
                $discountValue = $item['discount_value'] ?? null;

                try {
                    if ($discountType === InvoiceDiscountType::Percentage->value) {
                        InvoiceDecimal::percentage(is_int($discountValue) ? $discountValue : (string) $discountValue);
                    } elseif ($discountType === InvoiceDiscountType::Fixed->value) {
                        $fixed = InvoiceDecimal::money(is_int($discountValue) ? $discountValue : (string) $discountValue);
                        $gross = InvoiceDecimal::multiply(
                            InvoiceDecimal::quantity((string) ($item['quantity'] ?? '')),
                            InvoiceDecimal::money((string) ($item['unit_price'] ?? '')),
                        );

                        if (InvoiceDecimal::compare($fixed, $gross) > 0) {
                            throw new \InvalidArgumentException('Pevná sleva nesmí překročit hrubou hodnotu položky.');
                        }
                    } elseif ($discountValue !== null && InvoiceDecimal::compare((string) $discountValue, '0') !== 0) {
                        throw new \InvalidArgumentException('Položka bez slevy nesmí obsahovat hodnotu slevy.');
                    }
                } catch (\InvalidArgumentException $exception) {
                    $validator->errors()->add("items.{$index}.discount_value", $exception->getMessage());
                }
            }

            $discountType = (string) $this->input('invoice_discount_type', InvoiceDiscountType::None->value);
            $discountValue = $this->input('invoice_discount_value');

            try {
                if ($discountType === InvoiceDiscountType::Percentage->value) {
                    InvoiceDecimal::percentage(is_int($discountValue) ? $discountValue : (string) $discountValue);
                } elseif ($discountType === InvoiceDiscountType::Fixed->value) {
                    InvoiceDecimal::money(is_int($discountValue) ? $discountValue : (string) $discountValue);
                } elseif ($discountValue !== null && InvoiceDecimal::compare((string) $discountValue, '0') !== 0) {
                    throw new \InvalidArgumentException('Faktura bez celkové slevy nesmí obsahovat hodnotu slevy.');
                }
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('invoice_discount_value', $exception->getMessage());
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $items = array_map(static function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }

            foreach (['quantity', 'unit_price', 'discount_value'] as $field) {
                if (is_scalar($item[$field] ?? null)) {
                    $item[$field] = str_replace(',', '.', trim((string) $item[$field]));
                }
            }

            $item['description'] = trim((string) ($item['description'] ?? ''));
            $item['discount_type'] = $item['discount_type'] ?? InvoiceDiscountType::None->value;
            $item['unit'] = isset($item['unit']) && trim((string) $item['unit']) !== ''
                ? trim((string) $item['unit'])
                : null;

            return $item;
        }, (array) $this->input('items', []));

        $this->merge([
            'variable_symbol' => $this->filled('variable_symbol') ? trim((string) $this->input('variable_symbol')) : null,
            'note' => $this->filled('note') ? trim((string) $this->input('note')) : null,
            'invoice_discount_type' => $this->input('invoice_discount_type', InvoiceDiscountType::None->value),
            'invoice_discount_value' => $this->filled('invoice_discount_value')
                ? str_replace(',', '.', trim((string) $this->input('invoice_discount_value')))
                : null,
            'items' => $items,
        ]);
    }
}
