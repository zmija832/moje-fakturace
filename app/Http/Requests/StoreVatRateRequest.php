<?php

namespace App\Http\Requests;

use App\Models\Business\VatRate;
use Illuminate\Support\Facades\Gate;

class StoreVatRateRequest extends VatRateRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', VatRate::class);
    }

    public function rules(): array
    {
        return $this->vatRateRules();
    }
}
