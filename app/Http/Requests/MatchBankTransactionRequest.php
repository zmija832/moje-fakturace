<?php

namespace App\Http\Requests;

use App\Models\Business\BankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class MatchBankTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', BankAccount::class);
    }

    public function rules(): array
    {
        return ['invoice_uuid' => ['required', 'uuid']];
    }
}
