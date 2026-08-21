<?php

namespace App\Http\Requests;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Models\Business\InvoiceCatalogItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InvoiceCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->isMethod('POST') ? 'create' : 'updateAny', InvoiceCatalogItem::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'unit_price' => ['nullable', 'string', 'regex:/^\d{1,15}(?:[.,]\d{1,4})?$/'],
            'unit' => ['required', 'string', 'max:32'],
            'currency' => ['required', Rule::in(array_keys(CompanySettingOptions::CURRENCIES))],
            'vat_rate_uuid' => ['nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'unit_price' => $this->filled('unit_price')
                ? str_replace(',', '.', trim((string) $this->input('unit_price')))
                : null,
            'vat_rate_uuid' => $this->filled('vat_rate_uuid') ? $this->input('vat_rate_uuid') : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
