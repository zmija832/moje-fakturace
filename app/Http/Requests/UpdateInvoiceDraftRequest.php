<?php

namespace App\Http\Requests;

use App\Models\Business\Invoice;
use Illuminate\Support\Facades\Gate;

class UpdateInvoiceDraftRequest extends StoreInvoiceDraftRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', Invoice::class);
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['version'] = ['required', 'integer', 'min:1'];
        $rules['correlation_uuid'] = ['required', 'uuid'];
        $rules['items.*.position'] = ['required', 'integer', 'min:1', 'max:65535', 'distinct'];

        return $rules;
    }
}
