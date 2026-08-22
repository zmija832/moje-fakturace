<?php

namespace App\Http\Requests;

use App\Models\Business\BankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class FioBankAccountSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', BankAccount::class);
    }

    public function rules(): array
    {
        return [
            'is_enabled' => ['sometimes', 'boolean'],
            'token' => ['nullable', 'string', 'min:8', 'max:512'],
        ];
    }
}
