@props(['invoice'])
@php
    $revision = $invoice->status === \App\Enums\InvoiceStatus::Issued ? $invoice->issuedRevision : $invoice->currentRevision;
    $customer = $revision->customerSnapshot;
    $overdue = $invoice->status === \App\Enums\InvoiceStatus::Issued && $invoice->due_on->isBefore(today());
@endphp
<tr>
    <td class="px-4 py-4"><a class="font-semibold text-blue-700" href="{{ route('invoices.show',$invoice->uuid) }}">{{ $invoice->document_number ?? 'Návrh' }}</a><span class="mt-1 block text-xs text-slate-500">Revize {{ $revision->revision_number }}</span></td>
    <td class="px-4 py-4"><span class="font-medium">{{ $customer->display_name }}</span>@if($customer->registration_number)<span class="block text-xs text-slate-500">IČO {{ $customer->registration_number }}</span>@endif</td>
    <td class="px-4 py-4"><span class="block">{{ $invoice->issued_on->format('j. n. Y') }}</span><span class="block text-xs text-slate-500">Splatnost {{ $invoice->due_on->format('j. n. Y') }} · DUZP {{ $invoice->taxable_supply_on->format('j. n. Y') }}</span></td>
    <td class="px-4 py-4 text-right font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::format($revision->grand_total) }} {{ $invoice->currency }}</td>
    <td class="px-4 py-4"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold">{{ $invoice->status->label() }}</span>@if($overdue)<span class="mt-2 block text-xs font-semibold text-red-700">Po splatnosti*</span>@endif</td>
    <td class="px-4 py-4 text-right"><a class="button-secondary" href="{{ route('invoices.show',$invoice->uuid) }}">Detail</a> @can('update',$invoice)<a class="button-primary" href="{{ route('invoices.edit',$invoice->uuid) }}">Upravit</a>@endcan</td>
</tr>
