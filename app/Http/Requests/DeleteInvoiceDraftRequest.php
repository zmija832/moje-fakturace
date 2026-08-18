<?php

namespace App\Http\Requests;

use App\Models\Business\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DeleteInvoiceDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', Invoice::class);
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', Rule::in(['ODSTRANIT'])],
            'business_id' => ['prohibited'],
            'connection' => ['prohibited'],
        ];
    }
}
