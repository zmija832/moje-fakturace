<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class InvoiceReader
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly InvoicePaymentReader $paymentReader,
    ) {}

    public function find(string $invoiceUuid): Invoice
    {
        $this->connectionResolver->resolve();
        $invoice = Invoice::query()->where('uuid', $invoiceUuid)->firstOrFail();
        $revisionRelation = $invoice->status->hasIssuedDocument() ? 'issuedRevision' : 'currentRevision';

        return $invoice->load([
            'numberAllocation', 'documentSequence',
            $revisionRelation.'.supplierSnapshot', $revisionRelation.'.customerSnapshot',
            $revisionRelation.'.bankAccountSnapshot', $revisionRelation.'.vatSnapshots',
            $revisionRelation.'.items.vatSnapshot', $revisionRelation.'.vatSummaries',
            'documents.revision', 'emailDeliveries.document', 'payments.originalPayment', 'payments.reversals',
            'reminders',
        ]);
    }

    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, Invoice> */
    public function search(array $filters): LengthAwarePaginator
    {
        $this->connectionResolver->resolve();
        $query = Invoice::query()->with([
            'currentRevision:id,invoice_id,revision_number,grand_total',
            'currentRevision.customerSnapshot:invoice_revision_id,source_client_uuid,display_name,registration_number',
            'issuedRevision:id,invoice_id,revision_number,grand_total',
            'issuedRevision.customerSnapshot:invoice_revision_id,source_client_uuid,display_name,registration_number',
            'documents:id,uuid,invoice_id,invoice_revision_id,storage_path,storage_disk,original_filename,mime_type,generated_at',
        ])->select('invoices.*')->selectRaw(
            "(SELECT COALESCE(SUM(CASE WHEN invoice_payments.payment_type = 'payment' THEN invoice_payments.amount ELSE -invoice_payments.amount END), 0) FROM invoice_payments WHERE invoice_payments.invoice_id = invoices.id) AS payment_paid_total",
        );

        $visibility = $filters['visibility'] ?? 'active';
        if ($visibility === 'archived') {
            $query->whereNotNull('archived_at');
        } elseif ($visibility === 'cancelled') {
            $query->whereNull('archived_at')->where('status', InvoiceStatus::Cancelled->value);
        } elseif ($visibility === 'drafts') {
            $query->whereNull('archived_at')->where('status', InvoiceStatus::Draft->value);
        } elseif ($visibility === 'unpaid') {
            $query->whereNull('archived_at')->where('status', InvoiceStatus::Issued->value);
        } elseif ($visibility === 'paid') {
            $query->whereNull('archived_at')->where('status', InvoiceStatus::Issued->value);
        } elseif ($visibility !== 'all') {
            $query->whereNull('archived_at');
            $query->where('status', '!=', InvoiceStatus::Cancelled->value);
        }
        if (in_array($filters['status'] ?? null, array_column(InvoiceStatus::cases(), 'value'), true)) {
            $query->where('status', $filters['status']);
        }
        if (in_array($filters['currency'] ?? null, ['CZK', 'EUR'], true)) {
            $query->where('currency', $filters['currency']);
        }
        foreach (['issued_from' => '>=', 'issued_to' => '<=', 'due_from' => '>=', 'due_to' => '<='] as $field => $operator) {
            if (is_string($filters[$field] ?? null)) {
                $column = str_starts_with($field, 'issued_') ? 'issued_on' : 'due_on';
                $query->where($column, $operator, $filters[$field]);
            }
        }
        $paidSql = "(SELECT COALESCE(SUM(CASE WHEN ip.payment_type = 'payment' THEN ip.amount ELSE -ip.amount END), 0) FROM invoice_payments ip WHERE ip.invoice_id = invoices.id)";
        $grandTotalSql = '(SELECT ir.grand_total FROM invoice_revisions ir WHERE ir.id = invoices.issued_revision_id)';
        if ($visibility === 'unpaid') {
            $query->whereRaw("{$grandTotalSql} - {$paidSql} > 0");
        } elseif ($visibility === 'paid') {
            $query->whereRaw("{$paidSql} >= {$grandTotalSql}");
        }
        $paymentStatus = $filters['payment_status'] ?? null;
        if (in_array($paymentStatus, ['unpaid', 'partially_paid', 'paid', 'overpaid'], true)) {
            $query->where('status', InvoiceStatus::Issued->value);
            match ($paymentStatus) {
                'unpaid' => $query->whereRaw("{$paidSql} = 0"),
                'partially_paid' => $query->whereRaw("{$paidSql} > 0 AND {$paidSql} < {$grandTotalSql}"),
                'paid' => $query->whereRaw("{$paidSql} = {$grandTotalSql}"),
                'overpaid' => $query->whereRaw("{$paidSql} > {$grandTotalSql}"),
            };
        }
        if (in_array($filters['overdue'] ?? false, [true, '1', 1], true)) {
            $query->where('status', InvoiceStatus::Issued->value)
                ->whereDate('due_on', '<', today())
                ->whereRaw("{$grandTotalSql} - {$paidSql} > 0");
        }

        $clientUuid = $filters['client_uuid'] ?? null;
        if (is_string($clientUuid) && $clientUuid !== '') {
            $this->whereRevisionSnapshot($query, fn ($snapshot): mixed => $snapshot->where('source_client_uuid', $clientUuid));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $pattern = '%'.$escaped.'%';
            $query->where(function ($query) use ($search, $pattern): void {
                $query->where('document_number', 'like', $pattern)
                    ->orWhere('uuid', $search)
                    ->orWhere('variable_symbol', 'like', $pattern)
                    ->orWhereHas('currentRevision.customerSnapshot', fn ($snapshot) => $snapshot
                        ->where('display_name', 'like', $pattern)->orWhere('registration_number', 'like', $pattern))
                    ->orWhereHas('issuedRevision.customerSnapshot', fn ($snapshot) => $snapshot
                        ->where('display_name', 'like', $pattern)->orWhere('registration_number', 'like', $pattern));
            });
        }

        $sort = in_array($filters['sort'] ?? null, ['created_at', 'issued_on', 'due_on', 'document_number'], true)
            ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $invoices = $query->orderBy($sort, $direction)->orderBy('id', $direction)
            ->paginate(20)->withQueryString();
        $invoices->getCollection()->each(function (Invoice $invoice): void {
            $invoice->setRelation('paymentSummary', $this->paymentReader->summaryFromAggregate($invoice));
        });

        return $invoices;
    }

    private function whereRevisionSnapshot(mixed $query, callable $constraint): void
    {
        $query->where(function ($query) use ($constraint): void {
            $query->whereHas('currentRevision.customerSnapshot', $constraint)
                ->orWhereHas('issuedRevision.customerSnapshot', $constraint);
        });
    }
}
