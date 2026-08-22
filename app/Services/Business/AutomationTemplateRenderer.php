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

    public const PAID_PLACEHOLDERS = ['{invoice_number}', '{customer_name}', '{supplier_name}', '{amount}', '{paid_at}'];

    public function __construct(private readonly InvoicePublicLinkService $links) {}

    /** @return array{subject:string,body:string,recipient:?string,web_invoice_url:string} */
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
        $rendered['web_invoice_url'] = $url;

        return $rendered;
    }

    /** @return array{subject:string,body:string,recipient:?string} */
    public function paid(Invoice $invoice, string $subject, string $body, string $paidAt): array
    {
        return $this->render($invoice, $subject, $body, ['{paid_at}' => $paidAt], self::PAID_PLACEHOLDERS);
    }

    /** @return array{subject:string,body:string} */
    public function paymentNotification(Invoice $invoice, InvoicePaymentEventSnapshot $event, string $audience): array
    {
        $invoice->loadMissing('issuedRevision.customerSnapshot');
        $number = (string) $invoice->document_number;
        $customer = (string) $invoice->issuedRevision->customerSnapshot->display_name;
        $received = InvoiceDecimal::formatMoney($event->amount, $event->currency);
        $paid = InvoiceDecimal::formatMoney($event->paidTotal, $event->currency);
        $remaining = InvoiceDecimal::formatMoney($event->remainingTotal, $event->currency);
        $overpayment = InvoiceDecimal::formatMoney(InvoiceDecimal::absolute($event->remainingTotal), $event->currency);
        $status = InvoicePaymentStatus::from($event->statusAfter);

        if ($audience === 'admin') {
            return match ($status) {
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
        }

        return match ($status) {
            InvoicePaymentStatus::PartiallyPaid => [
                'subject' => "Potvrzení částečné úhrady faktury {$number}",
                'body' => "Dobrý den,\n\nevidujeme platbu ve výši {$received} k faktuře {$number}.\n\nCelkem uhrazeno: {$paid}\nZbývá uhradit: {$remaining}\n\nDěkujeme.",
            ],
            InvoicePaymentStatus::Paid => [
                'subject' => "Potvrzení úhrady faktury {$number}",
                'body' => "Dobrý den,\n\nevidujeme platbu ve výši {$received} k faktuře {$number}.\n\nFaktura je nyní plně uhrazena.\n\nDěkujeme.",
            ],
            InvoicePaymentStatus::Overpaid => [
                'subject' => "Potvrzení platby k faktuře {$number}",
                'body' => "Dobrý den,\n\nevidujeme platbu ve výši {$received} k faktuře {$number}.\n\nFaktura je uhrazena a evidujeme přeplatek ve výši {$overpayment}.\n\nDěkujeme.",
            ],
            default => throw ValidationException::withMessages(['payment' => 'Pro tento platební stav nelze vytvořit e-mailovou notifikaci.']),
        };
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
}
