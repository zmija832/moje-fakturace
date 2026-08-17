<?php

namespace App\Http\Requests;

class UpdateIssuedInvoiceRequest extends UpdateInvoiceDraftRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'admin_edit_confirmation' => ['required', 'accepted'],
        ];
    }
}
