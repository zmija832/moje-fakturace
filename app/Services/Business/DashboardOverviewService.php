<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Models\Business\Invoice;
use App\Models\Business\InvoicePaidNotification;
use App\Models\Business\InvoiceReminder;
use App\Models\Business\RecurringInvoiceRun;
use App\Models\Business\RecurringInvoiceTemplate;
use Illuminate\Support\Facades\DB;

class DashboardOverviewService
{
    public function __construct(
        private readonly BusinessConnectionResolver $resolver,
        private readonly BusinessDate $businessDate,
    ) {}

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $connection = $this->resolver->resolve()->connectionName();
        $today = $this->businessDate->today();
        $month = $today->startOfMonth()->format('Y-m-d');
        $next = $today->addMonth()->startOfMonth()->format('Y-m-d');
        $currencies = collect(DB::connection($connection)->select(<<<'SQL'
SELECT i.currency,
 SUM(CASE WHEN r.grand_total > COALESCE(p.paid_total,0) THEN r.grand_total-COALESCE(p.paid_total,0) ELSE 0 END) outstanding_total,
 SUM(CASE WHEN i.due_on < ? AND r.grand_total > COALESCE(p.paid_total,0) THEN r.grand_total-COALESCE(p.paid_total,0) ELSE 0 END) overdue_total,
 SUM(CASE WHEN i.due_on < ? AND r.grand_total > COALESCE(p.paid_total,0) THEN 1 ELSE 0 END) overdue_count,
 SUM(CASE WHEN r.grand_total > COALESCE(p.paid_total,0) THEN 1 ELSE 0 END) outstanding_count
FROM invoices i JOIN invoice_revisions r ON r.id=i.issued_revision_id
LEFT JOIN (SELECT invoice_id,SUM(CASE WHEN payment_type='payment' THEN amount ELSE -amount END) paid_total FROM invoice_payments GROUP BY invoice_id) p ON p.invoice_id=i.id
WHERE i.status='issued' AND i.archived_at IS NULL GROUP BY i.currency ORDER BY i.currency
SQL, [$today->format('Y-m-d'), $today->format('Y-m-d')]));
        $issued = collect(DB::connection($connection)->select('SELECT i.currency,COUNT(*) invoice_count,SUM(r.grand_total) total FROM invoices i JOIN invoice_revisions r ON r.id=i.issued_revision_id WHERE i.status=? AND i.archived_at IS NULL AND i.issued_at>=? AND i.issued_at<? GROUP BY i.currency', ['issued', $month, $next]));
        $paid = collect(DB::connection($connection)->select("SELECT currency,COUNT(*) payment_count,SUM(CASE WHEN payment_type='payment' THEN amount ELSE -amount END) total FROM invoice_payments WHERE paid_on>=? AND paid_on<? GROUP BY currency", [$month, $next]));

        return ['currencies' => $currencies, 'issuedThisMonth' => $issued, 'paidThisMonth' => $paid, 'draftCount' => Invoice::query()->where('status', 'draft')->whereNull('archived_at')->count(), 'recurringActive' => RecurringInvoiceTemplate::query()->where('is_active', true)->count(), 'recurringNext' => RecurringInvoiceTemplate::query()->where('is_active', true)->min('next_run_on'), 'dueRecurring' => RecurringInvoiceTemplate::query()->where('is_active', true)->whereDate('next_run_on', '<=', $today->format('Y-m-d'))->count(), 'failedAutomation' => RecurringInvoiceRun::query()->where('status', 'failed')->count() + InvoiceReminder::query()->where('status', 'failed')->count() + InvoicePaidNotification::query()->where('status', 'failed')->count(), 'adminPaidAlerts' => InvoicePaidNotification::query()->where('recipient_type', 'admin')->where('status', 'internal')->count()];
    }
}
