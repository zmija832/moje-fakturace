<?php

namespace App\Http\Requests;

use App\Models\Business\Invoice;
use Illuminate\Foundation\Http\FormRequest;

class AttachInvoiceLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateAny', Invoice::class) ?? false;
    }

    public function rules(): array
    {
        return ['label_uuid' => ['required', 'uuid']];
    }
}
