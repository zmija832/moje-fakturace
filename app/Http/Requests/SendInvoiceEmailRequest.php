<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendInvoiceEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'send_correlation_uuid' => ['required', 'uuid'],
            'recipient_email' => ['required', 'email:rfc', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'message' => ['nullable', 'string', 'max:2000'],
            'connection' => ['prohibited'], 'business_id' => ['prohibited'], 'storage_disk' => ['prohibited'],
            'storage_path' => ['prohibited'], 'invoice_document_id' => ['prohibited'], 'status' => ['prohibited'],
            'provider_message_id' => ['prohibited'], 'sent_at' => ['prohibited'], 'failed_at' => ['prohibited'],
            'failure_message' => ['prohibited'], 'sha256' => ['prohibited'], 'grand_total' => ['prohibited'],
            'snapshot' => ['prohibited'], 'qr_payload' => ['prohibited'],
        ];
    }
}
