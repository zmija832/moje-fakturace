<?php

namespace App\Models\Business;

use App\Enums\ClientType;
use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'type', 'display_name', 'company_name', 'first_name', 'last_name',
    'registration_number', 'tax_id', 'vat_id', 'email', 'phone', 'website',
    'contact_person', 'street', 'house_number', 'orientation_number', 'city',
    'postal_code', 'country_code', 'delivery_name', 'delivery_street',
    'delivery_house_number', 'delivery_orientation_number', 'delivery_city',
    'delivery_postal_code', 'delivery_country_code', 'default_currency',
    'default_due_days', 'default_payment_method', 'language', 'note', 'is_active',
])]
class Client extends BusinessModel
{
    use HasServerGeneratedUuid;

    public function isCompany(): bool
    {
        return $this->type === ClientType::Company;
    }

    public function isPerson(): bool
    {
        return $this->type === ClientType::Person;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function formattedBillingAddress(): string
    {
        return $this->formattedAddress('', required: true);
    }

    public function formattedDeliveryAddress(): ?string
    {
        if ($this->delivery_street === null) {
            return null;
        }

        return $this->formattedAddress('delivery_');
    }

    protected function casts(): array
    {
        return [
            'type' => ClientType::class,
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
            'default_due_days' => 'integer',
        ];
    }

    private function formattedAddress(string $prefix, bool $required = false): ?string
    {
        $street = $this->getAttribute($prefix.'street');

        if (! $required && $street === null) {
            return null;
        }

        $numbers = array_filter([
            $this->getAttribute($prefix.'house_number'),
            $this->getAttribute($prefix.'orientation_number'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
        $streetLine = trim((string) $street.' '.implode('/', $numbers));
        $cityLine = trim((string) $this->getAttribute($prefix.'postal_code').' '.(string) $this->getAttribute($prefix.'city'));

        return implode(', ', array_filter([$streetLine, $cityLine, $this->getAttribute($prefix.'country_code')]));
    }
}
