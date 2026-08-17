<?php

namespace App\Services\Business;

use App\Models\Business\Invoice;

class InvoiceEmailTemplateRenderer
{
    public const PLACEHOLDERS = [
        '{invoice_number}', '{customer_name}', '{supplier_name}', '{amount}', '{due_date}', '{web_invoice_url}',
    ];

    public function __construct(
        private readonly InvoiceEmailSettingsService $settings,
        private readonly InvoiceDocumentViewModelFactory $viewModels,
        private readonly InvoicePublicLinkService $publicLinks,
    ) {}

    /** @return array{sender_name:string,reply_to:?string,subject:string,body_text:string,body_html:string,attach_pdf:bool,public_url:?string} */
    public function render(Invoice $invoice): array
    {
        $setting = $this->settings->current();
        $model = $this->viewModels->make($invoice)->toArray();
        $publicUrl = $setting->include_web_invoice ? $this->publicLinks->activeUrlForInvoice($invoice) : null;
        $tokens = [
            '{invoice_number}' => (string) $invoice->document_number,
            '{customer_name}' => (string) $model['customer']['name'],
            '{supplier_name}' => (string) $model['supplier']['name'],
            '{amount}' => $model['totals']['grand_total'].' '.$model['currency'],
            '{due_date}' => (string) $model['due_on'],
            '{web_invoice_url}' => $publicUrl ?? '',
        ];
        $subject = $this->substitute($setting->subject_template, $tokens);
        $body = trim($this->substitute($setting->body_template, $tokens));
        $signature = trim($this->substitute((string) $setting->signature, $tokens));
        $text = trim($body.($signature === '' ? '' : "\n\n".$signature));
        $html = nl2br(e($text));
        if ($publicUrl !== null) {
            $html = str_replace(e($publicUrl), '<a href="'.e($publicUrl).'">Zobrazit fakturu online</a>', $html);
        }

        return [
            'sender_name' => trim((string) $setting->sender_name),
            'reply_to' => filter_var($setting->reply_to, FILTER_VALIDATE_EMAIL) ? $setting->reply_to : null,
            'subject' => $subject,
            'body_text' => $text,
            'body_html' => '<div style="font-family:Arial,sans-serif;line-height:1.5">'.$html.'</div>',
            'attach_pdf' => (bool) $setting->attach_pdf,
            'public_url' => $publicUrl,
        ];
    }

    /** @param array<string, string> $tokens */
    private function substitute(string $template, array $tokens): string
    {
        return strtr($template, $tokens);
    }
}
