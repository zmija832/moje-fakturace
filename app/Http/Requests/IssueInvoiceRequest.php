<?php

namespace App\Http\Requests;

use App\Models\Business\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class IssueInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('issue', Invoice::class);
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'correlation_uuid' => ['required', 'uuid'],
            'document_sequence_uuid' => ['nullable', 'uuid'],
            'status' => ['prohibited'],
            'document_number' => ['prohibited'],
            'allocation_id' => ['prohibited'],
            'document_number_allocation_id' => ['prohibited'],
            'issued_revision_id' => ['prohibited'],
            'issued_at' => ['prohibited'],
            'totals' => ['prohibited'],
            'connection' => ['prohibited'],
            'business_id' => ['prohibited'],
            'snapshots' => ['prohibited'],
            'document_uuid' => ['prohibited'],
        ];
    }
}
