<?php

namespace App\Http\Requests;

use App\Models\Business\Invoice;
use Illuminate\Foundation\Http\FormRequest;

class DuplicateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateAny', Invoice::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }
}
