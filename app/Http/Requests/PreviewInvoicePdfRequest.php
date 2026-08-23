<?php

namespace App\Http\Requests;

use App\Enums\InvoicePdfStyle;
use App\Models\Business\InvoiceEmailSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class PreviewInvoicePdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', InvoiceEmailSetting::class);
    }

    public function rules(): array
    {
        return [
            'pdf_style' => ['required', Rule::enum(InvoicePdfStyle::class)],
            'business_id' => ['prohibited'],
            'connection' => ['prohibited'],
        ];
    }
}
