<?php

namespace App\Http\Requests;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Models\Business\InvoiceCatalogItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InvoiceCatalogSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', InvoiceCatalogItem::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'currency' => ['required', Rule::in(array_keys(CompanySettingOptions::CURRENCIES))]];
    }
}
