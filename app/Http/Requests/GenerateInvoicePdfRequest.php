<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesBooleanInput;
use Illuminate\Foundation\Http\FormRequest;

class GenerateInvoicePdfRequest extends FormRequest
{
    use NormalizesBooleanInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['force_regenerate' => $this->normalizedBooleanInput('force_regenerate')]);
    }

    public function rules(): array
    {
        return [
            'generation_correlation_uuid' => ['required', 'uuid'],
            'force_regenerate' => ['sometimes', 'boolean'],
            'connection' => ['prohibited'], 'business_id' => ['prohibited'], 'storage_disk' => ['prohibited'],
            'storage_path' => ['prohibited'], 'status' => ['prohibited'], 'sha256' => ['prohibited'],
            'invoice_document_id' => ['prohibited'], 'provider_message_id' => ['prohibited'],
            'sent_at' => ['prohibited'], 'failed_at' => ['prohibited'], 'failure_message' => ['prohibited'],
            'grand_total' => ['prohibited'], 'snapshot' => ['prohibited'], 'qr_payload' => ['prohibited'],
        ];
    }
}
