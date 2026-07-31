<?php

namespace App\Http\Requests;

use App\Domain\Clients\ClientNormalizer;
use App\Domain\CompanySettings\CompanySettingOptions;
use App\Enums\ClientType;
use App\Enums\DefaultPaymentMethod;
use App\Http\Requests\Concerns\NormalizesBooleanInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ClientRequest extends FormRequest
{
    use NormalizesBooleanInput;

    /**
     * @return array<string, list<mixed>>
     */
    protected function clientRules(): array
    {
        $deliveryRequired = fn (): bool => $this->hasDeliveryAddress();

        return [
            'type' => ['required', Rule::enum(ClientType::class)],
            'display_name' => ['nullable', 'string', 'max:255'],
            'company_name' => [
                Rule::requiredIf(fn (): bool => $this->input('type') === ClientType::Company->value),
                'nullable', 'string', 'max:255',
            ],
            'first_name' => [
                Rule::requiredIf(fn (): bool => $this->input('type') === ClientType::Person->value),
                'nullable', 'string', 'max:128',
            ],
            'last_name' => [
                Rule::requiredIf(fn (): bool => $this->input('type') === ClientType::Person->value),
                'nullable', 'string', 'max:128',
            ],
            'registration_number' => ['nullable', 'string', 'regex:/^\d{2,32}$/'],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'vat_id' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:32'],
            'orientation_number' => ['nullable', 'string', 'max:32'],
            'city' => ['required', 'string', 'max:128'],
            'postal_code' => ['required', 'string', 'max:16'],
            'country_code' => ['required', Rule::in(array_keys(CompanySettingOptions::COUNTRIES))],
            'delivery_name' => ['nullable', 'string', 'max:255'],
            'delivery_street' => [Rule::requiredIf($deliveryRequired), 'nullable', 'string', 'max:255'],
            'delivery_house_number' => ['nullable', 'string', 'max:32'],
            'delivery_orientation_number' => ['nullable', 'string', 'max:32'],
            'delivery_city' => [Rule::requiredIf($deliveryRequired), 'nullable', 'string', 'max:128'],
            'delivery_postal_code' => [Rule::requiredIf($deliveryRequired), 'nullable', 'string', 'max:16'],
            'delivery_country_code' => [
                Rule::requiredIf($deliveryRequired),
                'nullable',
                Rule::in(array_keys(CompanySettingOptions::COUNTRIES)),
            ],
            'default_currency' => ['nullable', Rule::in(array_keys(CompanySettingOptions::CURRENCIES))],
            'default_due_days' => ['nullable', 'integer', 'between:0,365'],
            'default_payment_method' => ['nullable', Rule::enum(DefaultPaymentMethod::class)],
            'language' => ['nullable', Rule::in(array_keys(CompanySettingOptions::DOCUMENT_LOCALES))],
            'note' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'type' => 'typ klienta', 'display_name' => 'zobrazovaný název',
            'company_name' => 'název firmy', 'first_name' => 'jméno',
            'last_name' => 'příjmení', 'registration_number' => 'IČO',
            'tax_id' => 'DIČ', 'vat_id' => 'IČ DPH', 'email' => 'e-mail',
            'phone' => 'telefon', 'website' => 'web', 'contact_person' => 'kontaktní osoba',
            'street' => 'ulice', 'city' => 'město', 'postal_code' => 'PSČ',
            'country_code' => 'stát', 'delivery_street' => 'dodací ulice',
            'delivery_city' => 'dodací město', 'delivery_postal_code' => 'dodací PSČ',
            'delivery_country_code' => 'dodací stát', 'default_currency' => 'výchozí měna',
            'default_due_days' => 'výchozí splatnost',
            'default_payment_method' => 'výchozí způsob úhrady', 'language' => 'jazyk',
            'note' => 'poznámka', 'is_active' => 'aktivní klient',
        ];
    }

    protected function prepareForValidation(): void
    {
        $fields = array_keys($this->clientRules());
        $normalized = ClientNormalizer::normalize($this->only($fields));

        $normalized['is_active'] = $this->normalizedBooleanInput('is_active');

        $this->merge($normalized);
    }

    private function hasDeliveryAddress(): bool
    {
        foreach ([
            'delivery_name', 'delivery_street', 'delivery_house_number',
            'delivery_orientation_number', 'delivery_city', 'delivery_postal_code',
            'delivery_country_code',
        ] as $field) {
            if ($this->input($field) !== null && $this->input($field) !== '') {
                return true;
            }
        }

        return false;
    }
}
