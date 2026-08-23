<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\InvoiceMailStyle;
use App\Models\Business\InvoiceEmailSetting;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class InvoiceMailDesignRenderer
{
    public const LOGO_DISK = 'mail_branding';

    public function __construct(
        private readonly InvoiceEmailSettingsService $settings,
        private readonly ActiveBusinessContext $businessContext,
    ) {}

    /**
     * @param  array{title:string,preheader?:string,intro?:string,status?:string,status_tone?:string,facts?:list<array{label:string,value:string,emphasis?:bool}>,cta_url?:?string,cta_label?:?string,outro?:?string,internal?:bool}  $message
     * @return array{body_text:string,body_html:string}
     */
    public function render(array $message): array
    {
        $setting = $this->settings->current();

        return $this->renderWithSetting($message, $setting, $this->logoAttachment() !== null);
    }

    /**
     * @param  array{title:string,preheader?:string,intro?:string,status?:string,status_tone?:string,facts?:list<array{label:string,value:string,emphasis?:bool}>,cta_url?:?string,cta_label?:?string,outro?:?string,internal?:bool}  $message
     * @return array{body_text:string,body_html:string}
     */
    public function renderWithSetting(array $message, InvoiceEmailSetting $setting, bool $hasLogo): array
    {
        $style = InvoiceMailStyle::tryFrom((string) $setting->template_style) ?? InvoiceMailStyle::Modern;
        $signature = $this->signature($setting, (string) ($message['supplier_name'] ?? ''));
        [$textMessage, $htmlMessage] = $this->messagesWithoutDuplicatedCtaUrl($message);

        return [
            'body_text' => trim(view('mail.design.document-text', ['message' => $textMessage, 'signature' => $signature])->render()),
            'body_html' => view('mail.design.document-html', [
                'messageData' => $htmlMessage,
                'signature' => $signature,
                'style' => $style,
                'theme' => $style->theme(),
                'hasLogo' => $hasLogo,
                'senderName' => (string) $setting->sender_name,
            ])->render(),
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function messagesWithoutDuplicatedCtaUrl(array $message): array
    {
        $url = trim((string) ($message['cta_url'] ?? ''));
        $intro = (string) ($message['intro'] ?? '');
        if ($url === '' || ! str_contains($intro, $url)) {
            return [$message, $message];
        }

        $textLines = [];
        $htmlLines = [];
        $htmlReplacementAdded = false;
        foreach (preg_split('/\R/u', $intro) ?: [] as $line) {
            if (str_contains($line, $url)) {
                if (! $htmlReplacementAdded) {
                    $htmlLines[] = 'Fakturu můžete zobrazit online pomocí tlačítka níže.';
                    $htmlReplacementAdded = true;
                }

                continue;
            }

            $textLines[] = $line;
            $htmlLines[] = $line;
        }

        $textMessage = $message;
        $textMessage['intro'] = trim(implode("\n", $textLines));
        $htmlMessage = $message;
        $htmlMessage['intro'] = trim(implode("\n", $htmlLines));

        return [$textMessage, $htmlMessage];
    }

    /** @return null|array{data:string,mime:string,name:string} */
    public function logoAttachment(): ?array
    {
        $setting = $this->settings->current();
        $path = trim((string) $setting->logo_path);
        $businessPrefix = $this->businessContext->requireBusiness()->uuid.'/';
        if ($path === '' || ! str_starts_with($path, $businessPrefix)) {
            return null;
        }

        try {
            $disk = Storage::disk(self::LOGO_DISK);
            if (! $disk->exists($path)) {
                return null;
            }
            $mime = (string) $disk->mimeType($path);
            if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                return null;
            }

            return ['data' => $disk->get($path), 'mime' => $mime, 'name' => 'logo.'.match ($mime) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            }];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /** @return array{name:?string,role:?string,phone:?string,email:?string,web:?string,text:?string,legacy:?string} */
    private function signature(InvoiceEmailSetting $setting, string $supplierName): array
    {
        $structured = collect([
            'name' => $setting->signature_name,
            'role' => $setting->signature_role,
            'phone' => $setting->signature_phone,
            'email' => $setting->signature_email,
            'web' => $setting->signature_web,
            'text' => $setting->signature_text,
        ])->map(fn ($value): ?string => filled($value) ? trim((string) $value) : null)->all();

        return [...$structured, 'legacy' => collect($structured)->filter()->isEmpty() && filled($setting->signature)
            ? strtr(trim((string) $setting->signature), ['{supplier_name}' => $supplierName])
            : null];
    }
}
