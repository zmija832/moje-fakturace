<?php

namespace App\Http\Requests;

use App\Models\Business\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CancelInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', Invoice::class);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'correlation_uuid' => ['required', 'uuid'],
            'business_id' => ['prohibited'],
            'connection' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason'))]);
    }
}
