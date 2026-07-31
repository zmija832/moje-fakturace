<?php

namespace App\Http\Requests;

use App\Models\Business\BankAccount;
use Illuminate\Support\Facades\Gate;

class StoreBankAccountRequest extends BankAccountRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', BankAccount::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->bankAccountRules();
    }
}
