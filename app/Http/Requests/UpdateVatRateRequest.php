<?php

namespace App\Http\Requests;

use App\Models\Business\VatRate;
use Illuminate\Support\Facades\Gate;

class UpdateVatRateRequest extends VatRateRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', VatRate::class);
    }

    public function rules(): array
    {
        return $this->vatRateRules();
    }
}
