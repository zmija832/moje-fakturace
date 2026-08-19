<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Models\Business\InvoiceAutomationSetting;
use Illuminate\Support\Facades\DB;

class InvoiceAutomationSettingsService
{
    public function __construct(private readonly BusinessConnectionResolver $resolver, private readonly BusinessAuditSanitizer $sanitizer, private readonly BusinessAuditWriter $audit) {}

    public function current(): InvoiceAutomationSetting
    {
        return InvoiceAutomationSetting::query()->where('singleton_key', InvoiceAutomationSetting::SINGLETON_KEY)->first() ?? new InvoiceAutomationSetting($this->defaults());
    }

    /** @param array<string,mixed> $data */
    public function save(array $data): InvoiceAutomationSetting
    {
        $connection = $this->resolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($data): InvoiceAutomationSetting {
            $setting = InvoiceAutomationSetting::query()->where('singleton_key', '1')->lockForUpdate()->first() ?? new InvoiceAutomationSetting;
            $before = $setting->exists ? $this->sanitizer->snapshot(BusinessAuditableType::InvoiceAutomationSettings, $setting) : null;
            $setting->forceFill(['singleton_key' => '1']);
            $setting->fill($data)->save();
            $after = $this->sanitizer->snapshot(BusinessAuditableType::InvoiceAutomationSettings, $setting);
            $this->audit->write(BusinessAuditEvent::InvoiceAutomationSettingsUpdated, BusinessAuditableType::InvoiceAutomationSettings, null, $before, $after, array_keys($after));

            return $setting->refresh();
        }, 3);
    }

    /** @return array<string,mixed> */
    public function defaults(): array
    {
        return ['reminders_enabled' => false, 'reminder_mode' => 'prepare', 'reminder_day_1' => 1, 'reminder_day_2' => 7, 'reminder_day_3' => 14, 'reminder_subject_1' => 'Připomínka splatnosti faktury {invoice_number}', 'reminder_body_1' => "Dobrý den, připomínáme fakturu {invoice_number} se splatností {due_date}. Zbývá uhradit {remaining_amount}.\n{web_invoice_url}", 'reminder_subject_2' => 'Faktura {invoice_number} je stále po splatnosti', 'reminder_body_2' => "Dobrý den, faktura {invoice_number} je {days_overdue} dní po splatnosti. Prosíme o úhradu částky {remaining_amount}.\n{web_invoice_url}", 'reminder_subject_3' => 'Důležité upozornění k faktuře {invoice_number}', 'reminder_body_3' => "Dobrý den, faktura {invoice_number} zůstává {days_overdue} dní po splatnosti. Kontaktujte nás prosím nebo uhraďte {remaining_amount}.\n{web_invoice_url}", 'notify_admin_when_paid' => true, 'notify_customer_when_paid' => false, 'paid_subject' => 'Potvrzení úhrady faktury {invoice_number}', 'paid_body' => "Dobrý den, děkujeme, platbu faktury {invoice_number} jsme přijali.\n\n{supplier_name}"];
    }
}
