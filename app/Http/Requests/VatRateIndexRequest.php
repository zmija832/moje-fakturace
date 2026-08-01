<?php

namespace App\Http\Requests;

use App\Enums\VatTaxType;
use App\Models\Business\VatRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VatRateIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', VatRate::class);
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['current', 'active', 'inactive', 'archived', 'all'])],
            'tax_type' => ['nullable', Rule::enum(VatTaxType::class)],
            'valid_on' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', Rule::in(['sort_order', 'name', 'code', 'tax_type', 'valid_from'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'connection' => ['prohibited'],
            'business_id' => ['prohibited'],
        ];
    }
}
