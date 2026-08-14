<?php

namespace App\Http\Requests;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Enums\DefaultPaymentMethod;
use App\Models\Business\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('recordPayment', Invoice::class);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^[0-9]{1,15}(?:\.[0-9]{1,4})?$/'],
            'currency' => ['required', Rule::in(array_keys(CompanySettingOptions::CURRENCIES))],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'received_at' => ['nullable', 'date'],
            'payment_method' => ['required', Rule::enum(DefaultPaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'variable_symbol' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]*$/'],
            'note' => ['nullable', 'string', 'max:2000'],
            'correlation_uuid' => ['required', 'uuid'],
            'connection' => ['prohibited'], 'business_id' => ['prohibited'], 'invoice_id' => ['prohibited'],
            'payment_status' => ['prohibited'], 'paid_total' => ['prohibited'], 'remaining_total' => ['prohibited'],
            'created_by_actor' => ['prohibited'], 'external_id' => ['prohibited'], 'reverses_payment_id' => ['prohibited'],
            'created_at' => ['prohibited'], 'updated_at' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'amount' => str_replace(',', '.', trim((string) $this->input('amount'))),
            'currency' => strtoupper(trim((string) $this->input('currency'))),
            'reference' => $this->filled('reference') ? trim((string) $this->input('reference')) : null,
            'variable_symbol' => $this->filled('variable_symbol') ? trim((string) $this->input('variable_symbol')) : null,
            'note' => $this->filled('note') ? trim((string) $this->input('note')) : null,
        ]);
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Zadejte částku úhrady.',
            'amount.regex' => 'Částka úhrady musí být kladné desetinné číslo s nejvýše čtyřmi desetinnými místy.',
            'paid_on.required' => 'Zadejte datum úhrady.',
            'paid_on.date_format' => 'Datum úhrady nemá platný formát.',
            'payment_method.required' => 'Vyberte způsob úhrady.',
        ];
    }
}
