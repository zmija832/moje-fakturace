<?php

namespace App\Http\Requests;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Enums\DefaultPaymentMethod;
use App\Http\Requests\Concerns\NormalizesBooleanInput;
use App\Models\Business\CompanySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateCompanySettingRequest extends FormRequest
{
    use NormalizesBooleanInput;

    public function authorize(): bool
    {
        return Gate::allows('updateAny', CompanySetting::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'additional_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['required', 'string', 'regex:/^\d{6,10}$/'],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'vat_id' => ['nullable', 'required_if:is_vat_payer,true', 'string', 'max:32'],
            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:32'],
            'orientation_number' => ['nullable', 'string', 'max:32'],
            'city' => ['required', 'string', 'max:128'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country_code' => ['required', 'string', Rule::in(array_keys(CompanySettingOptions::COUNTRIES))],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'default_currency' => ['required', 'string', Rule::in(array_keys(CompanySettingOptions::CURRENCIES))],
            'document_locale' => ['required', 'string', Rule::in(array_keys(CompanySettingOptions::DOCUMENT_LOCALES))],
            'timezone' => ['required', 'string', Rule::in(array_keys(CompanySettingOptions::TIMEZONES))],
            'is_vat_payer' => ['required', 'boolean'],
            'vat_registered_on' => ['nullable', 'date'],
            'default_due_days' => ['required', 'integer', 'between:0,365'],
            'default_payment_method' => ['required', Rule::enum(DefaultPaymentMethod::class)],
            'invoice_intro' => ['nullable', 'string', 'max:5000'],
            'invoice_outro' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'legal_name' => 'oficiální název',
            'additional_name' => 'doplňující název',
            'registration_number' => 'IČO',
            'tax_id' => 'DIČ',
            'vat_id' => 'IČ DPH',
            'street' => 'ulice',
            'house_number' => 'číslo popisné',
            'orientation_number' => 'číslo orientační',
            'city' => 'město',
            'postal_code' => 'PSČ',
            'country_code' => 'stát',
            'email' => 'e-mail',
            'phone' => 'telefon',
            'website' => 'web',
            'default_currency' => 'měna',
            'document_locale' => 'jazyk dokladů',
            'timezone' => 'časové pásmo',
            'is_vat_payer' => 'plátce DPH',
            'vat_registered_on' => 'datum registrace k DPH',
            'default_due_days' => 'výchozí splatnost',
            'default_payment_method' => 'výchozí způsob úhrady',
            'invoice_intro' => 'text před položkami',
            'invoice_outro' => 'text ve spodní části faktury',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_vat_payer' => $this->normalizedBooleanInput('is_vat_payer'),
        ]);
    }
}
