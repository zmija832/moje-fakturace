<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Models\Business\CompanySetting;
use App\Models\Business\InvoiceEmailSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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
    public function save(array $attributes, ?UploadedFile $logo = null, bool $removeLogo = false): InvoiceEmailSetting
    {
        $connection = $this->connectionResolver->resolve()->connectionName();
        $newLogoPath = null;
        if ($logo !== null) {
            $businessUuid = $this->context->requireBusiness()->uuid;
            $extension = match ($logo->getMimeType()) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $newLogoPath = $businessUuid.'/'.Str::uuid().'.'.$extension;
            Storage::disk(InvoiceMailDesignRenderer::LOGO_DISK)->putFileAs($businessUuid, $logo, basename($newLogoPath));
        }

        try {
            [$setting, $oldLogoPath] = DB::connection($connection)->transaction(function () use ($attributes, $newLogoPath, $removeLogo): array {
                $setting = InvoiceEmailSetting::query()
                    ->where('singleton_key', InvoiceEmailSetting::SINGLETON_KEY)->lockForUpdate()->first();
                $created = $setting === null;
                $before = $setting === null ? null : $this->auditSanitizer->snapshot(BusinessAuditableType::InvoiceEmailSettings, $setting);
                if ($setting === null) {
                    $setting = new InvoiceEmailSetting;
                    $setting->forceFill(['singleton_key' => InvoiceEmailSetting::SINGLETON_KEY]);
                }
                $oldLogoPath = $setting->logo_path;
                $setting->fill($attributes);
                if ($newLogoPath !== null || $removeLogo) {
                    $setting->logo_path = $newLogoPath;
                }
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

                return [$setting->refresh(), $oldLogoPath];
            }, 3);
        } catch (Throwable $exception) {
            if ($newLogoPath !== null) {
                try {
                    Storage::disk(InvoiceMailDesignRenderer::LOGO_DISK)->delete($newLogoPath);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            throw $exception;
        }

        if (($newLogoPath !== null || $removeLogo) && filled($oldLogoPath) && $oldLogoPath !== $newLogoPath) {
            try {
                Storage::disk(InvoiceMailDesignRenderer::LOGO_DISK)->delete((string) $oldLogoPath);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $setting;
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
            'template_style' => 'modern',
            'pdf_style' => 'classic',
            'logo_path' => null,
            'subject_template' => 'Faktura {invoice_number} od {supplier_name}',
            'body_template' => "Dobrý den, {customer_name},\n\nzasíláme fakturu {invoice_number} na částku {amount}.\nSplatnost: {due_date}.\n{web_invoice_url}\n\nPDF faktury je přiloženo podle nastavení odesílání.",
            'signature' => "S pozdravem\n{supplier_name}",
            'signature_name' => null,
            'signature_role' => null,
            'signature_phone' => null,
            'signature_email' => null,
            'signature_web' => null,
            'signature_text' => null,
            'attach_pdf' => true,
            'include_web_invoice' => true,
        ];
    }
}
