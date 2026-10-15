<?php

namespace App\Http\Requests;

use App\Enums\InvoiceLabelColor;
use App\Models\Business\InvoiceLabel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InvoiceLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->isMethod('POST') ? 'create' : 'updateAny', InvoiceLabel::class);
    }

    public function rules(): array
    {
        $label = InvoiceLabel::query()->where('uuid', (string) $this->route('uuid'))->first();

        return [
            'name' => ['required', 'string', 'max:80', Rule::unique(InvoiceLabel::class, 'name')->ignore($label?->id)],
            'color' => ['required', Rule::enum(InvoiceLabelColor::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
