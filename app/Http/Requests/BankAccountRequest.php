<?php

namespace App\Http\Requests;

use App\Domain\BankAccounts\BankAccountNormalizer;
use App\Domain\CompanySettings\CompanySettingOptions;
use App\Http\Requests\Concerns\NormalizesBooleanInput;
use App\Rules\ValidIban;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class BankAccountRequest extends FormRequest
{
    use NormalizesBooleanInput;

    /**
     * @return array<string, list<mixed>>
     */
    protected function bankAccountRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'currency' => [
                'required',
                'string',
                Rule::in(array_keys(CompanySettingOptions::CURRENCIES)),
            ],
            'domestic_prefix' => [
                'nullable',
                'string',
                'max:10',
                'regex:/^\d+$/',
                Rule::prohibitedIf(fn (): bool => $this->input('domestic_account_number') === null),
            ],
            'domestic_account_number' => [
                'nullable',
                'required_without:iban',
                'string',
                'max:32',
                'regex:/^\d+$/',
            ],
            'bank_code' => [
                'nullable',
                'required_with:domestic_account_number',
                Rule::prohibitedIf(fn (): bool => $this->input('domestic_account_number') === null),
                'string',
                'regex:/^\d{4}$/',
            ],
            'iban' => [
                'nullable',
                'required_without:domestic_account_number',
                'string',
                'max:34',
                new ValidIban,
            ],
            'bic' => [
                'nullable',
                'string',
                'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/',
            ],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'název účtu',
            'currency' => 'měna',
            'domestic_prefix' => 'prefix',
            'domestic_account_number' => 'číslo účtu',
            'bank_code' => 'kód banky',
            'iban' => 'IBAN',
            'bic' => 'BIC/SWIFT',
            'is_active' => 'aktivní účet',
            'sort_order' => 'pořadí',
            'note' => 'poznámka',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = BankAccountNormalizer::normalize($this->only([
            'name',
            'currency',
            'domestic_prefix',
            'domestic_account_number',
            'bank_code',
            'iban',
            'bic',
        ]));

        $normalized['is_active'] = $this->normalizedBooleanInput('is_active');

        $this->merge($normalized);
    }
}
