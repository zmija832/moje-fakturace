<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\InvoicePaymentSummary;
use App\Models\Business\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class InvoicePaymentReader
{
    public function __construct(private readonly BusinessConnectionResolver $connectionResolver) {}

    public function summary(Invoice $invoice): InvoicePaymentSummary
    {
        if (! $invoice->status->hasIssuedDocument()) {
            throw new LogicException('Platební souhrn je dostupný pouze pro vystavenou fakturu.');
        }

        $invoice->loadMissing(['issuedRevision:id,invoice_id,grand_total', 'payments.originalPayment']);

        return InvoicePaymentSummary::fromLedger(
            $invoice->issuedRevision->grand_total,
            $invoice->payments->map(fn ($payment): array => [
                'payment_type' => $payment->payment_type,
                'amount' => $payment->amount,
            ]),
            $invoice->due_on,
            today(),
        );
    }

    public function summaryFromAggregate(Invoice $invoice): ?InvoicePaymentSummary
    {
        if (! $invoice->status->hasIssuedDocument()) {
            return null;
        }

        return InvoicePaymentSummary::fromTotals(
            $invoice->issuedRevision->grand_total,
            (string) ($invoice->getAttribute('payment_paid_total') ?? '0'),
            $invoice->due_on,
            today(),
        );
    }

    /** @return Collection<int, object> */
    public function dashboard(): Collection
    {
        $connection = $this->connectionResolver->resolve()->connectionName();
        $rows = DB::connection($connection)->select(<<<'SQL'
SELECT i.currency,
       COUNT(*) AS invoice_count,
       SUM(CASE WHEN COALESCE(p.paid_total, 0) = 0 THEN 1 ELSE 0 END) AS unpaid_count,
       SUM(CASE WHEN COALESCE(p.paid_total, 0) > 0 AND COALESCE(p.paid_total, 0) < r.grand_total THEN 1 ELSE 0 END) AS partially_paid_count,
       SUM(CASE WHEN COALESCE(p.paid_total, 0) = r.grand_total THEN 1 ELSE 0 END) AS paid_count,
       SUM(CASE WHEN COALESCE(p.paid_total, 0) > r.grand_total THEN 1 ELSE 0 END) AS overpaid_count,
       SUM(CASE WHEN r.grand_total > COALESCE(p.paid_total, 0) THEN r.grand_total - COALESCE(p.paid_total, 0) ELSE 0 END) AS outstanding_total,
       SUM(CASE WHEN COALESCE(p.paid_total, 0) > r.grand_total THEN COALESCE(p.paid_total, 0) - r.grand_total ELSE 0 END) AS overpayment_total
FROM invoices i
JOIN invoice_revisions r ON r.id = i.issued_revision_id
LEFT JOIN (
    SELECT invoice_id, SUM(CASE WHEN payment_type = 'payment' THEN amount ELSE -amount END) AS paid_total
    FROM invoice_payments GROUP BY invoice_id
) p ON p.invoice_id = i.id
WHERE i.status = 'issued'
GROUP BY i.currency
ORDER BY i.currency
SQL);

        return collect($rows);
    }
}
