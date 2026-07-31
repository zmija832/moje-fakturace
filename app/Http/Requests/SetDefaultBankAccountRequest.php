<?php

namespace App\Http\Requests;

use App\Models\Business\BankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SetDefaultBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', BankAccount::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
