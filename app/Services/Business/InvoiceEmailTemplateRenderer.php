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
        private readonly InvoiceMailDesignRenderer $mailDesign,
    ) {}

    /** @return array{sender_name:string,reply_to:?string,subject:string,body_text:string,body_html:string,attach_pdf:bool,public_url:?string} */
    public function render(Invoice $invoice, string $additionalMessage = ''): array
    {
        $setting = $this->settings->current();
        $model = $this->viewModels->make($invoice)->toArray();
        $publicUrl = $setting->include_web_invoice ? $this->publicLinks->activeUrlForInvoice($invoice) : null;
        $tokens = [
            '{invoice_number}' => (string) $invoice->document_number,
            '{customer_name}' => (string) $model['customer']['name'],
            '{supplier_name}' => (string) $model['supplier']['name'],
            '{amount}' => $model['totals']['grand_total'].' '.$model['currency_label'],
            '{due_date}' => (string) $model['due_on'],
            '{web_invoice_url}' => $publicUrl ?? '',
        ];
        $subject = $this->substitute($setting->subject_template, $tokens);
        $body = trim($this->substitute($setting->body_template, $tokens));
        $designed = $this->mailDesign->render([
            'title' => 'Faktura '.$invoice->document_number,
            'supplier_name' => (string) $model['supplier']['name'],
            'preheader' => 'Nová faktura od '.$model['supplier']['name'],
            'intro' => $body,
            'status' => 'Vystavena',
            'facts' => [
                ['label' => 'Faktura', 'value' => (string) $invoice->document_number],
                ['label' => 'Dodavatel', 'value' => (string) $model['supplier']['name']],
                ['label' => 'Částka', 'value' => $model['totals']['grand_total'].' '.$model['currency_label'], 'emphasis' => true],
                ['label' => 'Datum vystavení', 'value' => (string) $model['issued_on']],
                ['label' => 'Datum splatnosti', 'value' => (string) $model['due_on']],
                ['label' => 'Variabilní symbol', 'value' => (string) $model['variable_symbol']],
            ],
            'cta_url' => $publicUrl,
            'cta_label' => 'Zobrazit fakturu',
            'outro' => filled($additionalMessage) ? trim($additionalMessage) : null,
        ]);

        return [
            'sender_name' => trim((string) $setting->sender_name),
            'reply_to' => filter_var($setting->reply_to, FILTER_VALIDATE_EMAIL) ? $setting->reply_to : null,
            'subject' => $subject,
            'body_text' => $designed['body_text'],
            'body_html' => $designed['body_html'],
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
