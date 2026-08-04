<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class InvoiceReader
{
    public function __construct(private readonly BusinessConnectionResolver $connectionResolver) {}

    public function find(string $invoiceUuid): Invoice
    {
        $this->connectionResolver->resolve();
        $invoice = Invoice::query()->where('uuid', $invoiceUuid)->firstOrFail();
        $revisionRelation = $invoice->status === InvoiceStatus::Issued ? 'issuedRevision' : 'currentRevision';

        return $invoice->load([
            'numberAllocation', 'documentSequence',
            $revisionRelation.'.supplierSnapshot', $revisionRelation.'.customerSnapshot',
            $revisionRelation.'.bankAccountSnapshot', $revisionRelation.'.vatSnapshots',
            $revisionRelation.'.items.vatSnapshot', $revisionRelation.'.vatSummaries',
            'documents', 'emailDeliveries.document',
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
        ]);

        if (in_array($filters['status'] ?? null, [InvoiceStatus::Draft->value, InvoiceStatus::Issued->value], true)) {
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
        if (($filters['overdue'] ?? false) === true) {
            $query->where('status', InvoiceStatus::Issued->value)->whereDate('due_on', '<', today());
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

        return $query->orderBy($sort, $direction)->orderBy('id', $direction)
            ->paginate(20)->withQueryString();
    }

    private function whereRevisionSnapshot(mixed $query, callable $constraint): void
    {
        $query->where(function ($query) use ($constraint): void {
            $query->whereHas('currentRevision.customerSnapshot', $constraint)
                ->orWhereHas('issuedRevision.customerSnapshot', $constraint);
        });
    }
}
