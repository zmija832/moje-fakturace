<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\DefaultPaymentMethod;
use App\Models\Business\CompanySetting;
use Illuminate\Support\Facades\DB;

class CompanySettingsService
{
    public function __construct(
        private readonly ActiveBusinessContext $context,
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    public function forForm(): CompanySetting
    {
        $setting = CompanySetting::query()
            ->where('singleton_key', CompanySetting::SINGLETON_KEY)
            ->first();

        return $setting ?? new CompanySetting($this->defaults());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function save(array $attributes): CompanySetting
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($attributes): CompanySetting {
            $setting = CompanySetting::query()
                ->where('singleton_key', CompanySetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->first();

            $created = ! $setting;
            $oldValues = $setting
                ? $this->auditSanitizer->snapshot(BusinessAuditableType::CompanySettings, $setting)
                : null;

            if ($created) {
                $setting = new CompanySetting;
                $setting->forceFill(['singleton_key' => CompanySetting::SINGLETON_KEY]);
            }

            $setting->fill($attributes);
            $changedFields = $this->auditSanitizer->changedFields(BusinessAuditableType::CompanySettings, $setting);
            $setting->save();

            if ($created || $changedFields !== []) {
                $this->auditWriter->write(
                    $created ? BusinessAuditEvent::CompanySettingsCreated : BusinessAuditEvent::CompanySettingsUpdated,
                    BusinessAuditableType::CompanySettings,
                    null,
                    $oldValues,
                    $this->auditSanitizer->snapshot(BusinessAuditableType::CompanySettings, $setting),
                    $created ? array_keys($this->auditSanitizer->snapshot(BusinessAuditableType::CompanySettings, $setting)) : $changedFields,
                );
            }

            return $setting->refresh();
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        $business = $this->context->requireBusiness();

        return [
            'legal_name' => $business->display_name,
            'additional_name' => null,
            'registration_number' => $business->registration_number,
            'tax_id' => null,
            'vat_id' => null,
            'street' => '',
            'house_number' => null,
            'orientation_number' => null,
            'city' => '',
            'postal_code' => '',
            'country_code' => 'CZ',
            'email' => '',
            'phone' => null,
            'website' => null,
            'default_currency' => 'CZK',
            'document_locale' => 'cs',
            'timezone' => 'Europe/Prague',
            'is_vat_payer' => false,
            'vat_registered_on' => null,
            'default_due_days' => 14,
            'default_payment_method' => DefaultPaymentMethod::BankTransfer->value,
            'invoice_intro' => null,
            'invoice_outro' => null,
        ];
    }
}
