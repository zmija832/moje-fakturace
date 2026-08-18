<?php

namespace App\Http\Requests;

use App\Models\Business\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class DeleteInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', Invoice::class);
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'accepted'],
            'business_id' => ['prohibited'],
            'connection' => ['prohibited'],
        ];
    }
}
