<?php

namespace App\Http\Requests;

class PreviewInvoiceRequest extends StoreInvoiceDraftRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['customer_uuid', 'bank_account_uuid', 'issued_on', 'due_on', 'variable_symbol', 'note'] as $field) {
            unset($rules[$field]);
        }

        $rules['items.*.position'] = ['required', 'integer', 'min:1', 'max:65535', 'distinct'];
        $rules['items.*.description'] = ['nullable', 'string', 'max:255'];
        $rules['items.*.unit'] = ['nullable', 'string', 'max:32'];

        return $rules;
    }

    protected function requiresBankAccountForTransfer(): bool
    {
        return false;
    }
}
