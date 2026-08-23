<?php

namespace App\Services\Business;

use App\Domain\Invoices\InvoiceDecimal;
use App\Domain\Invoices\InvoicePaymentEventSnapshot;
use App\Enums\InvoicePaymentStatus;
use App\Models\Business\Invoice;
use Illuminate\Validation\ValidationException;

class AutomationTemplateRenderer
{
    public const REMINDER_PLACEHOLDERS = ['{invoice_number}', '{customer_name}', '{supplier_name}', '{amount}', '{remaining_amount}', '{due_date}', '{days_overdue}', '{web_invoice_url}'];

    public const PAID_PLACEHOLDERS = [
        '{invoice_number}',
        '{customer_name}',
        '{supplier_name}',
        '{amount}',
        '{payment_amount}',
        '{paid_total}',
        '{remaining}',
        '{overpayment}',
        '{payment_date}',
        '{paid_at}',
    ];

    public function __construct(
        private readonly InvoicePublicLinkService $links,
        private readonly InvoiceMailDesignRenderer $mailDesign,
    ) {}

    /** @return array{subject:string,body:string,body_html:string,recipient:?string,web_invoice_url:string} */
    public function reminder(Invoice $invoice, string $subject, string $body, string $remaining, int $days): array
    {
        $link = $this->links->create($invoice);
        $url = $this->links->url($link);
        if ($url === null) {
            throw ValidationException::withMessages(['web_invoice_url' => 'Veřejný odkaz faktury nelze bezpečně vytvořit.']);
        }
        $rendered = $this->render($invoice, $subject, $body, ['{remaining_amount}' => InvoiceDecimal::formatMoney($remaining, $invoice->currency), '{days_overdue}' => (string) $days, '{web_invoice_url}' => $url], self::REMINDER_PLACEHOLDERS);
        if (! str_contains($rendered['body'], $url)) {
            $rendered['body'] = trim($rendered['body'])."\n\nWebfaktura: {$url}";
        }
        $revision = $invoice->issuedRevision;
        $paid = InvoiceDecimal::subtract($revision->grand_total, $remaining);
        $designed = $this->mailDesign->render([
            'title' => 'Upomínka k faktuře '.$invoice->document_number,
            'supplier_name' => (string) $revision->supplierSnapshot->legal_name,
            'intro' => $rendered['body'],
            'status' => $days.' dní po splatnosti',
            'status_tone' => 'warning',
            'facts' => [
                ['label' => 'Faktura', 'value' => (string) $invoice->document_number],
                ['label' => 'Původní částka', 'value' => InvoiceDecimal::formatMoney($revision->grand_total, $invoice->currency)],
                ['label' => 'Uhrazeno', 'value' => InvoiceDecimal::formatMoney($paid, $invoice->currency)],
                ['label' => 'Zbývá uhradit', 'value' => InvoiceDecimal::formatMoney($remaining, $invoice->currency), 'emphasis' => true],
                ['label' => 'Splatnost', 'value' => $revision->due_on->format('j. n. Y')],
                ['label' => 'Variabilní symbol', 'value' => (string) $revision->variable_symbol],
            ],
            'cta_url' => $url,
            'cta_label' => 'Zobrazit fakturu',
        ]);
        $rendered['body'] = $designed['body_text'];
        $rendered['body_html'] = $designed['body_html'];
        $rendered['web_invoice_url'] = $url;

        return $rendered;
    }

    /** @return array{subject:string,body:string,recipient:?string} */
    public function paid(Invoice $invoice, string $subject, string $body, string $paidAt): array
    {
        return $this->render($invoice, $subject, $body, ['{paid_at}' => $paidAt], self::PAID_PLACEHOLDERS);
    }

    /** @return array{subject:string,body:string,body_html:string,web_invoice_url:?string} */
    public function paymentNotification(
        Invoice $invoice,
        InvoicePaymentEventSnapshot $event,
        string $audience,
        ?string $customSubject = null,
        ?string $customBody = null,
    ): array {
        $invoice->loadMissing('issuedRevision.customerSnapshot');
        $number = (string) $invoice->document_number;
        $customer = (string) $invoice->issuedRevision->customerSnapshot->display_name;
        $received = InvoiceDecimal::formatMoney($event->amount, $event->currency);
        $paid = InvoiceDecimal::formatMoney($event->paidTotal, $event->currency);
        $remaining = InvoiceDecimal::formatMoney($event->remainingTotal, $event->currency);
        $overpayment = InvoiceDecimal::formatMoney(InvoiceDecimal::absolute($event->remainingTotal), $event->currency);
        $status = InvoicePaymentStatus::from($event->statusAfter);

        if ($audience === 'admin') {
            $content = match ($status) {
                InvoicePaymentStatus::PartiallyPaid => [
                    'subject' => "Částečná úhrada faktury {$number}",
                    'body' => "Faktura: {$number}\nKlient: {$customer}\nPřijatá platba: {$received}\nCelkem uhrazeno: {$paid}\nZbývá: {$remaining}\nStav: Částečně uhrazena",
                ],
                InvoicePaymentStatus::Paid => [
                    'subject' => "Faktura {$number} byla uhrazena",
                    'body' => "Faktura: {$number}\nKlient: {$customer}\nPřijatá platba: {$received}\nCelkem uhrazeno: {$paid}\nZbývá: {$remaining}\nStav: Uhrazená",
                ],
                InvoicePaymentStatus::Overpaid => [
                    'subject' => "Faktura {$number} je přeplacena",
                    'body' => "Faktura: {$number}\nKlient: {$customer}\nPřijatá platba: {$received}\nCelkem uhrazeno: {$paid}\nPřeplatek: {$overpayment}\nStav: Přeplacená",
                ],
                default => throw ValidationException::withMessages(['payment' => 'Pro tento platební stav nelze vytvořit e-mailovou notifikaci.']),
            };
        } else {
            $content = match ($status) {
                InvoicePaymentStatus::PartiallyPaid => [
                    'subject' => "Potvrzení částečné úhrady faktury {$number}",
                    'body' => "Dobrý den,\n\nevidujeme platbu ve výši {$received} k faktuře {$number}. Děkujeme.",
                ],
                InvoicePaymentStatus::Paid => [
                    'subject' => "Potvrzení úhrady faktury {$number}",
                    'body' => "Dobrý den,\n\nfaktura {$number} je nyní plně uhrazena. Děkujeme za platbu.",
                ],
                InvoicePaymentStatus::Overpaid => [
                    'subject' => "Potvrzení platby k faktuře {$number}",
                    'body' => "Dobrý den,\n\nevidujeme platbu ve výši {$received} k faktuře {$number}. Faktura je uhrazena a evidujeme přeplatek ve výši {$overpayment}.",
                ],
                default => throw ValidationException::withMessages(['payment' => 'Pro tento platební stav nelze vytvořit e-mailovou notifikaci.']),
            };
            if ($customSubject !== null && $customBody !== null) {
                $custom = $this->render($invoice, $customSubject, $customBody, [
                    '{payment_amount}' => $received,
                    '{paid_total}' => $paid,
                    '{remaining}' => $remaining,
                    '{overpayment}' => $overpayment,
                    '{payment_date}' => $this->date($event->paidOn),
                    '{paid_at}' => $event->paidOn,
                ], self::PAID_PLACEHOLDERS);
                $content = ['subject' => $custom['subject'], 'body' => $custom['body']];
            }
        }

        $url = null;
        if ($audience !== 'admin') {
            $url = $this->links->url($this->links->create($invoice));
        }
        $revision = $invoice->issuedRevision;
        $facts = [
            ['label' => 'Faktura', 'value' => $number],
            ['label' => 'Faktura celkem', 'value' => InvoiceDecimal::formatMoney($revision->grand_total, $event->currency)],
            ['label' => 'Přijatá platba', 'value' => $received],
            ['label' => 'Celkem uhrazeno', 'value' => $paid],
        ];
        if ($status === InvoicePaymentStatus::PartiallyPaid) {
            $facts[] = ['label' => 'Zbývá uhradit', 'value' => $remaining, 'emphasis' => true];
        }
        if ($status === InvoicePaymentStatus::Overpaid) {
            $facts[] = ['label' => 'Přeplatek', 'value' => $overpayment, 'emphasis' => true];
        }
        $facts[] = ['label' => 'Datum platby', 'value' => $this->date($event->paidOn)];
        $facts[] = ['label' => 'Splatnost', 'value' => $revision->due_on->format('j. n. Y')];
        $facts[] = ['label' => 'Variabilní symbol', 'value' => (string) $revision->variable_symbol];
        $label = match ($status) {
            InvoicePaymentStatus::PartiallyPaid => 'Částečně uhrazeno',
            InvoicePaymentStatus::Paid => 'Uhrazeno',
            InvoicePaymentStatus::Overpaid => 'Přeplaceno',
            default => '',
        };
        $designed = $this->mailDesign->render([
            'title' => $content['subject'],
            'supplier_name' => (string) $revision->supplierSnapshot->legal_name,
            'intro' => $content['body'],
            'status' => $label,
            'status_tone' => $status === InvoicePaymentStatus::Overpaid ? 'warning' : 'success',
            'facts' => $facts,
            'cta_url' => $url,
            'cta_label' => 'Zobrazit fakturu',
            'internal' => $audience === 'admin',
        ]);

        return ['subject' => $content['subject'], 'body' => $designed['body_text'], 'body_html' => $designed['body_html'], 'web_invoice_url' => $url];
    }

    /** @param array<string,string> $extra @param list<string> $allowed @return array{subject:string,body:string,recipient:?string} */
    private function render(Invoice $invoice, string $subject, string $body, array $extra, array $allowed): array
    {
        $invoice->loadMissing(['issuedRevision.customerSnapshot', 'issuedRevision.supplierSnapshot']);
        $revision = $invoice->issuedRevision;
        $values = ['{invoice_number}' => (string) $invoice->document_number, '{customer_name}' => (string) $revision->customerSnapshot->display_name, '{supplier_name}' => (string) $revision->supplierSnapshot->legal_name, '{amount}' => InvoiceDecimal::formatMoney($revision->grand_total, $invoice->currency), '{due_date}' => $revision->due_on->format('d. m. Y'), ...$extra];
        $this->assertSafe($subject, $allowed);
        $this->assertSafe($body, $allowed);

        return ['subject' => strtr($subject, $values), 'body' => strtr($body, $values), 'recipient' => $revision->customerSnapshot->email];
    }

    /** @param list<string> $allowed */
    public function assertSafe(string $template, array $allowed): void
    {
        preg_match_all('/\{[a-z_]+\}/', $template, $matches);
        if (array_diff(array_unique($matches[0]), $allowed) !== [] || str_contains($template, '<?') || preg_match('/@php\b/i', $template)) {
            throw ValidationException::withMessages(['template' => 'Šablona obsahuje nepovolený výraz.']);
        }
    }

    private function date(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed ? $parsed->format('j. n. Y') : $date;
    }
}
