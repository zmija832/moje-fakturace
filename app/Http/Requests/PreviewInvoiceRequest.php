<?php

namespace App\Http\Requests;

class PreviewInvoiceRequest extends StoreInvoiceDraftRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['items.*.position'] = ['required', 'integer', 'min:1', 'max:65535', 'distinct'];

        return $rules;
    }
}
