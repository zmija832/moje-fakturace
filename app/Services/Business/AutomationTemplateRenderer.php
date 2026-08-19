<?php

namespace App\Services\Business;

use App\Models\Business\Invoice;
use Illuminate\Validation\ValidationException;

class AutomationTemplateRenderer
{
    public const REMINDER_PLACEHOLDERS = ['{invoice_number}', '{customer_name}', '{supplier_name}', '{amount}', '{remaining_amount}', '{due_date}', '{days_overdue}', '{web_invoice_url}'];

    public const PAID_PLACEHOLDERS = ['{invoice_number}', '{customer_name}', '{supplier_name}', '{amount}', '{paid_at}'];

    public function __construct(private readonly InvoicePublicLinkService $links) {}

    /** @return array{subject:string,body:string,recipient:?string} */
    public function reminder(Invoice $invoice, string $subject, string $body, string $remaining, int $days): array
    {
        return $this->render($invoice, $subject, $body, ['{remaining_amount}' => $remaining.' '.$invoice->currency, '{days_overdue}' => (string) $days, '{web_invoice_url}' => $this->links->activeUrlForInvoice($invoice) ?? ''], self::REMINDER_PLACEHOLDERS);
    }

    /** @return array{subject:string,body:string,recipient:?string} */
    public function paid(Invoice $invoice, string $subject, string $body, string $paidAt): array
    {
        return $this->render($invoice, $subject, $body, ['{paid_at}' => $paidAt], self::PAID_PLACEHOLDERS);
    }

    /** @param array<string,string> $extra @param list<string> $allowed @return array{subject:string,body:string,recipient:?string} */
    private function render(Invoice $invoice, string $subject, string $body, array $extra, array $allowed): array
    {
        $invoice->loadMissing(['issuedRevision.customerSnapshot', 'issuedRevision.supplierSnapshot']);
        $revision = $invoice->issuedRevision;
        $values = ['{invoice_number}' => (string) $invoice->document_number, '{customer_name}' => (string) $revision->customerSnapshot->display_name, '{supplier_name}' => (string) $revision->supplierSnapshot->legal_name, '{amount}' => $revision->grand_total.' '.$invoice->currency, '{due_date}' => $revision->due_on->format('d. m. Y'), ...$extra];
        $this->assertSafe($subject, $allowed);
        $this->assertSafe($body, $allowed);

        return ['subject' => strtr($subject, $values), 'body' => strtr($body, $values), 'recipient' => $revision->customerSnapshot->email];
    }

    /** @param list<string> $allowed */
    public function assertSafe(string $template, array $allowed): void
    {
        preg_match_all('/\{[a-z_]+\}/', $template, $matches);
        if (array_diff(array_unique($matches[0]), $allowed) !== [] || str_contains($template, '<?') || preg_match('/@php\b/i',$template)) {
            throw ValidationException::withMessages(['template' => 'Šablona obsahuje nepovolený výraz.']);
        }
    }
}
