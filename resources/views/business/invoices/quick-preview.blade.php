@php
    $customer = $revision->customerSnapshot;
    $paymentLabel = $paymentSummary?->status->label() ?? $invoice->status->label();
@endphp
<div class="space-y-6 p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="mb-2 text-lg font-bold">{{ $invoice->document_number ?? 'Návrh' }}</p>
            <p class="text-sm text-slate-500">Klient</p>
            <p class="font-semibold">{{ $customer->display_name }}</p>
            @if($customer->registration_number)<p class="text-sm text-slate-600">IČO {{ $customer->registration_number }}</p>@endif
        </div>
        <div class="text-right">
            <p class="text-sm text-slate-500">Celkem</p>
            <p class="text-xl font-bold">{{ \App\Domain\Invoices\InvoiceDecimal::formatMoney($revision->grand_total, $invoice->currency) }}</p>
            <p class="text-sm font-medium text-blue-700">{{ $paymentLabel }}</p>
        </div>
    </div>

    @if($invoice->labels->isNotEmpty())
        <div class="flex flex-wrap gap-1">@foreach($invoice->labels as $label)<x-invoices.label-badge :label="$label" />@endforeach</div>
    @endif

    <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
        <div><dt class="text-slate-500">Vystaveno</dt><dd>{{ $revision->issued_on->format('j. n. Y') }}</dd></div>
        <div><dt class="text-slate-500">Splatnost</dt><dd>{{ $revision->due_on->format('j. n. Y') }}</dd></div>
        <div><dt class="text-slate-500">Variabilní symbol</dt><dd>{{ $revision->variable_symbol ?: '—' }}</dd></div>
    </dl>

    <div class="overflow-x-auto rounded-xl border border-slate-200">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50"><tr><th class="px-4 py-3">Položka</th><th class="px-4 py-3 text-right">Množství</th><th class="px-4 py-3 text-right">Celkem</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($revision->items as $item)
                    <tr><td class="px-4 py-3">{{ $item->description }}</td><td class="px-4 py-3 text-right">{{ \App\Domain\Invoices\InvoiceDecimal::formatInput($item->quantity) }} {{ $item->unit }}</td><td class="px-4 py-3 text-right font-medium">{{ \App\Domain\Invoices\InvoiceDecimal::formatMoney($item->line_total_amount, $invoice->currency) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
