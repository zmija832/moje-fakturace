<?php

namespace App\Http\Requests;

use App\Models\Business\BankAccount;
use Illuminate\Support\Facades\Gate;

class UpdateBankAccountRequest extends BankAccountRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', BankAccount::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->bankAccountRules();
    }
}
