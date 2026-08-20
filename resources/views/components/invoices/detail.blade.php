@props(['invoice','revision'])
<div class="grid gap-6 xl:grid-cols-2">
    <section class="card"><h2 class="text-lg font-bold">Údaje dokladu</h2><dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-slate-500">Datum vystavení</dt><dd>{{ $revision->issued_on->format('j. n. Y') }}</dd></div><div><dt class="text-slate-500">DUZP</dt><dd>{{ $revision->taxable_supply_on->format('j. n. Y') }}</dd></div><div><dt class="text-slate-500">Splatnost</dt><dd>{{ $revision->due_on->format('j. n. Y') }}</dd></div><div><dt class="text-slate-500">Měna</dt><dd>{{ \App\Domain\Invoices\InvoiceDecimal::currencyLabel($revision->currency) }}</dd></div><div><dt class="text-slate-500">Způsob úhrady</dt><dd>{{ $revision->payment_method->label() }}</dd></div><div><dt class="text-slate-500">Variabilní symbol</dt><dd>{{ $revision->variable_symbol ?? '—' }}</dd></div>@if($invoice->issued_at)<div><dt class="text-slate-500">Vystaveno v systému</dt><dd>{{ $invoice->issued_at->format('j. n. Y H:i:s') }}</dd></div>@endif</dl>@if($revision->note)<div class="mt-4 border-t border-slate-200 pt-4"><h3 class="text-sm font-semibold">Poznámka</h3><p class="mt-1 whitespace-pre-line text-sm">{{ $revision->note }}</p></div>@endif</section>
    <x-invoices.parties :revision="$revision" />
</div>
<x-invoices.items-detail :revision="$revision" />
<x-invoices.totals :revision="$revision" />
