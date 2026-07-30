<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'legal_name',
    'additional_name',
    'registration_number',
    'tax_id',
    'vat_id',
    'street',
    'house_number',
    'orientation_number',
    'city',
    'postal_code',
    'country_code',
    'email',
    'phone',
    'website',
    'default_currency',
    'document_locale',
    'timezone',
    'is_vat_payer',
    'vat_registered_on',
    'default_due_days',
    'default_payment_method',
    'invoice_intro',
    'invoice_outro',
])]
class CompanySetting extends BusinessModel
{
    public const SINGLETON_KEY = '1';

    protected function casts(): array
    {
        return [
            'is_vat_payer' => 'boolean',
            'vat_registered_on' => 'date',
            'default_due_days' => 'integer',
        ];
    }
}
