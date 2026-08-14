@props(['invoice'])
@php
    $revision = $invoice->status === \App\Enums\InvoiceStatus::Issued ? $invoice->issuedRevision : $invoice->currentRevision;
    $customer = $revision->customerSnapshot;
    $paymentSummary = $invoice->paymentSummary;
@endphp
<tr>
    <td class="px-4 py-4"><a class="font-semibold text-blue-700" href="{{ route('invoices.show',$invoice->uuid) }}">{{ $invoice->document_number ?? 'Návrh' }}</a><span class="mt-1 block text-xs text-slate-500">Revize {{ $revision->revision_number }}</span></td>
    <td class="px-4 py-4"><span class="font-medium">{{ $customer->display_name }}</span>@if($customer->registration_number)<span class="block text-xs text-slate-500">IČO {{ $customer->registration_number }}</span>@endif</td>
    <td class="px-4 py-4"><span class="block">{{ $invoice->issued_on->format('j. n. Y') }}</span><span class="block text-xs text-slate-500">Splatnost {{ $invoice->due_on->format('j. n. Y') }} · DUZP {{ $invoice->taxable_supply_on->format('j. n. Y') }}</span></td>
    <td class="px-4 py-4 text-right font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::format($revision->grand_total) }} {{ $invoice->currency }}</td>
    <td class="px-4 py-4 text-right">@if($paymentSummary)<span class="font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::format($paymentSummary->paidTotal) }} {{ $invoice->currency }}</span><span class="block text-xs text-slate-500">zbývá {{ \App\Domain\Invoices\InvoiceDecimal::format($paymentSummary->remainingTotal) }}</span>@else<span class="text-slate-400">—</span>@endif</td>
    <td class="px-4 py-4"><x-invoices.status-badge :invoice="$invoice" :payment-summary="$paymentSummary" /></td>
    <td class="px-4 py-4 text-right"><x-invoices.list-actions :invoice="$invoice" /></td>
</tr>
