<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;

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
        ]);
    }
}
