<?php

namespace App\Http\Requests;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Enums\InvoicePaymentStatus;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InvoiceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Invoice::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', ...array_column(InvoiceStatus::cases(), 'value')])],
            'client_uuid' => ['nullable', 'uuid'],
            'currency' => ['nullable', Rule::in(['all', ...array_keys(CompanySettingOptions::CURRENCIES)])],
            'issued_from' => ['nullable', 'date_format:Y-m-d'],
            'issued_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_from'],
            'due_from' => ['nullable', 'date_format:Y-m-d'],
            'due_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:due_from'],
            'overdue' => ['nullable', 'boolean'],
            'payment_status' => ['nullable', Rule::in(['all', ...array_column(InvoicePaymentStatus::cases(), 'value')])],
            'sort' => ['nullable', Rule::in(['created_at', 'issued_on', 'due_on', 'document_number'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'connection' => ['prohibited'],
            'business_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? trim((string) $this->input('search')) : null,
            'status' => $this->input('status', 'all'),
            'currency' => $this->input('currency', 'all'),
            'payment_status' => $this->input('payment_status', 'all'),
        ]);
    }
}
