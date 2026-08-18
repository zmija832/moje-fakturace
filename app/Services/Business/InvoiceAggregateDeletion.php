<?php

namespace App\Services\Business;

use Illuminate\Support\Facades\DB;

class InvoiceAggregateDeletion
{
    /** @param list<int> $revisionIds */
    public function deleteRevisions(string $connection, array $revisionIds): void
    {
        if ($revisionIds === []) {
            return;
        }

        foreach (['invoice_items', 'invoice_vat_summaries', 'invoice_vat_snapshots'] as $table) {
            DB::connection($connection)->table($table)->whereIn('invoice_revision_id', $revisionIds)->delete();
        }
        foreach (['invoice_bank_account_snapshots', 'invoice_customer_snapshots', 'invoice_supplier_snapshots'] as $table) {
            DB::connection($connection)->table($table)->whereIn('invoice_revision_id', $revisionIds)->delete();
        }
        DB::connection($connection)->table('invoice_revisions')->whereIn('id', $revisionIds)->delete();
    }
}
