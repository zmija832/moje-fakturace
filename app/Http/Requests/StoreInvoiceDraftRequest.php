<?php

namespace App\Http\Requests;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Domain\Invoices\InvoiceDecimal;
use App\Enums\DefaultPaymentMethod;
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
            'items' => ['required', 'array', 'between:1,500'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'string', 'max:32'],
            'items.*.unit' => ['nullable', 'string', 'max:32'],
            'items.*.unit_price' => ['required', 'string', 'max:32'],
            'items.*.vat_rate_uuid' => ['required', 'uuid'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'status' => ['prohibited'],
            'document_type' => ['prohibited'],
            'document_number' => ['prohibited'],
            'connection' => ['prohibited'],
            'business_id' => ['prohibited'],
            'supplier_snapshot' => ['prohibited'],
            'customer_snapshot' => ['prohibited'],
            'bank_account_snapshot' => ['prohibited'],
            'vat_snapshot' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
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
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $items = array_map(static function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }

            foreach (['quantity', 'unit_price'] as $field) {
                if (is_scalar($item[$field] ?? null)) {
                    $item[$field] = str_replace(',', '.', trim((string) $item[$field]));
                }
            }

            $item['description'] = trim((string) ($item['description'] ?? ''));
            $item['unit'] = isset($item['unit']) && trim((string) $item['unit']) !== ''
                ? trim((string) $item['unit'])
                : null;

            return $item;
        }, (array) $this->input('items', []));

        $this->merge([
            'variable_symbol' => $this->filled('variable_symbol') ? trim((string) $this->input('variable_symbol')) : null,
            'note' => $this->filled('note') ? trim((string) $this->input('note')) : null,
            'items' => $items,
        ]);
    }
}
