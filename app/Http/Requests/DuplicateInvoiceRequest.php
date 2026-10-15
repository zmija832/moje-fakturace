<?php

namespace App\Http\Requests;

use App\Models\Business\Client;
use App\Models\Business\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'client_mode' => ['required', Rule::in(['original', 'selected'])],
            'selected_client_uuid' => ['exclude_unless:client_mode,selected', 'required', 'uuid'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('client_mode') !== 'selected' || ! $this->filled('selected_client_uuid')) {
                return;
            }

            if (! Client::query()
                ->where('uuid', $this->input('selected_client_uuid'))
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->exists()) {
                $validator->errors()->add('selected_client_uuid', 'Vyberte aktivního klienta.');
            }
        }];
    }
}
