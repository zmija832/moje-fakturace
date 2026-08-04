<?php

namespace App\Http\Requests;

use App\Models\Business\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReverseInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('reversePayment', Invoice::class);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^[0-9]{1,15}(?:\.[0-9]{1,4})?$/'],
            'reversed_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
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
            'reason' => trim((string) $this->input('reason')),
        ]);
    }
}
