<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\InvoiceDecimal;
use App\Enums\InvoiceStatus;
use App\Models\Business\Client;
use App\Models\Business\Invoice;
use Illuminate\Support\Collection;

class ClientBillingOverview
{
    public function __construct(private readonly BusinessConnectionResolver $resolver, private readonly InvoicePaymentReader $payments) {}

    /** @return array{history:Collection<int,Invoice>,totals:array<string,array<string,mixed>>} */
    public function forClient(Client $client): array
    {
        $this->resolver->resolve();
        $query = Invoice::query()->with(['currentRevision.customerSnapshot', 'issuedRevision.customerSnapshot', 'payments.originalPayment'])
            ->where(function ($query) use ($client): void {
                $query->whereHas('currentRevision.customerSnapshot', fn ($snapshot) => $snapshot->where('source_client_uuid', $client->uuid))
                    ->orWhereHas('issuedRevision.customerSnapshot', fn ($snapshot) => $snapshot->where('source_client_uuid', $client->uuid));
            })->latest('created_at')->latest('id');
        $all = $query->get();
        $totals = [];
        foreach ($all as $invoice) {
            $revision = $invoice->status->hasIssuedDocument() ? $invoice->issuedRevision : $invoice->currentRevision;
            $invoice->setRelation('displayRevision', $revision);
            $summary = $invoice->status === InvoiceStatus::Issued ? $this->payments->summary($invoice) : null;
            $invoice->setRelation('paymentSummary', $summary);
            if ($invoice->status !== InvoiceStatus::Issued) {
                continue;
            }
            $currency = $invoice->currency;
            $totals[$currency] ??= ['count' => 0, 'issued' => '0.0000', 'paid' => '0.0000', 'outstanding' => '0.0000'];
            $totals[$currency]['count']++;
            $totals[$currency]['issued'] = InvoiceDecimal::add($totals[$currency]['issued'], $revision->grand_total);
            $totals[$currency]['paid'] = InvoiceDecimal::add($totals[$currency]['paid'], $summary->paidTotal);
            $totals[$currency]['outstanding'] = InvoiceDecimal::add($totals[$currency]['outstanding'], $summary->remainingTotal);
        }
        ksort($totals);

        return ['history' => $all->take(20), 'totals' => $totals];
    }
}
