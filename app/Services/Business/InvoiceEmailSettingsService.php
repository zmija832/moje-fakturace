<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Models\Business\CompanySetting;
use App\Models\Business\InvoiceEmailSetting;
use Illuminate\Support\Facades\DB;

class InvoiceEmailSettingsService
{
    public function __construct(
        private readonly ActiveBusinessContext $context,
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    public function current(): InvoiceEmailSetting
    {
        $setting = InvoiceEmailSetting::query()->where('singleton_key', InvoiceEmailSetting::SINGLETON_KEY)->first();

        return $setting ?? new InvoiceEmailSetting($this->defaults());
    }

    /** @param array<string, mixed> $attributes */
    public function save(array $attributes): InvoiceEmailSetting
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($attributes): InvoiceEmailSetting {
            $setting = InvoiceEmailSetting::query()
                ->where('singleton_key', InvoiceEmailSetting::SINGLETON_KEY)->lockForUpdate()->first();
            $created = $setting === null;
            $before = $setting === null ? null : $this->auditSanitizer->snapshot(BusinessAuditableType::InvoiceEmailSettings, $setting);
            if ($setting === null) {
                $setting = new InvoiceEmailSetting;
                $setting->forceFill(['singleton_key' => InvoiceEmailSetting::SINGLETON_KEY]);
            }
            $setting->fill($attributes);
            $changed = $this->auditSanitizer->changedFields(BusinessAuditableType::InvoiceEmailSettings, $setting);
            $setting->save();
            if ($created || $changed !== []) {
                $after = $this->auditSanitizer->snapshot(BusinessAuditableType::InvoiceEmailSettings, $setting);
                $this->auditWriter->write(
                    $created ? BusinessAuditEvent::InvoiceEmailSettingsCreated : BusinessAuditEvent::InvoiceEmailSettingsUpdated,
                    BusinessAuditableType::InvoiceEmailSettings,
                    null,
                    $before,
                    $after,
                    $created ? array_keys($after) : $changed,
                );
            }

            return $setting->refresh();
        }, 3);
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        $company = CompanySetting::query()->where('singleton_key', CompanySetting::SINGLETON_KEY)->first();
        $supplier = trim((string) $company?->legal_name);
        if ($supplier === '') {
            $supplier = $this->context->requireBusiness()->display_name;
        }

        return [
            'sender_name' => $supplier,
            'reply_to' => filter_var($company?->email, FILTER_VALIDATE_EMAIL) ? $company->email : null,
            'subject_template' => 'Faktura {invoice_number} od {supplier_name}',
            'body_template' => "Dobrý den, {customer_name},\n\nzasíláme fakturu {invoice_number} na částku {amount}.\nSplatnost: {due_date}.\n{web_invoice_url}\n\nPDF faktury je přiloženo podle nastavení odesílání.",
            'signature' => "S pozdravem\n{supplier_name}",
            'attach_pdf' => true,
            'include_web_invoice' => true,
        ];
    }
}
