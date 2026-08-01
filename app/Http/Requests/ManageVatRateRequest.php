<?php

namespace App\Http\Requests;

use App\Models\Business\VatRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ManageVatRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', VatRate::class);
    }

    public function rules(): array
    {
        return ['connection' => ['prohibited'], 'business_id' => ['prohibited']];
    }
}
