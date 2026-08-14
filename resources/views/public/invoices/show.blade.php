<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="no-referrer">
    <title>Faktura {{ $document['document_number'] }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
        <article class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <header class="flex flex-col gap-5 border-b border-slate-200 p-6 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-blue-700">Webfaktura</p>
                    <h1 class="mt-1 text-3xl font-bold">Faktura {{ $document['document_number'] }}</h1>
                    @if($document['is_non_payer'])<span class="mt-3 inline-flex rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">Neplátce DPH</span>@endif
                </div>
                <div class="sm:text-right">
                    <div class="mb-2"><span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $paymentSummary->status->label() }}</span></div>
                    <p class="text-sm text-slate-500">Celkem k fakturaci</p>
                    <p class="text-3xl font-bold tabular-nums">{{ $document['totals']['grand_total'] }} {{ $document['currency'] }}</p>
                    @if($hasPdf)<a class="button-primary mt-4" href="{{ $pdfUrl }}">Stáhnout PDF</a>@endif
                </div>
            </header>

            <div class="grid gap-6 p-6 md:grid-cols-2">
                <section><h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Dodavatel</h2><p class="mt-2 font-bold">{{ $document['supplier']['name'] }}</p><p class="mt-1 whitespace-pre-line text-sm">{{ $document['supplier']['address'] }}</p><p class="mt-2 text-sm">IČO {{ $document['supplier']['registration_number'] }}@if($document['supplier']['tax_id']) · DIČ {{ $document['supplier']['tax_id'] }}@endif</p></section>
                <section><h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Odběratel</h2><p class="mt-2 font-bold">{{ $document['customer']['name'] }}</p><p class="mt-1 whitespace-pre-line text-sm">{{ $document['customer']['address'] }}</p>@if($document['customer']['registration_number'])<p class="mt-2 text-sm">IČO {{ $document['customer']['registration_number'] }}</p>@endif</section>
            </div>

            <dl class="grid gap-px border-y border-slate-200 bg-slate-200 sm:grid-cols-5">
                @foreach(['Vystavení' => $document['issued_on'], 'DUZP' => $document['taxable_supply_on'], 'Splatnost' => $document['due_on'], 'Variabilní symbol' => ($document['variable_symbol'] ?: '—'), 'Úhrada' => $document['payment_method']] as $label => $value)
                    <div class="bg-white p-4"><dt class="text-xs uppercase tracking-wide text-slate-500">{{ $label }}</dt><dd class="mt-1 font-semibold">{{ $value }}</dd></div>
                @endforeach
            </dl>

            <section class="p-6">
                <h2 class="text-lg font-bold">Položky</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="border-b border-slate-300 text-left"><th class="py-2 pr-4">Popis</th><th class="py-2 pr-4 text-right">Množství</th><th class="py-2 pr-4 text-right">Jednotková cena</th><th class="py-2 pr-4 text-right">Sleva</th>@unless($document['is_non_payer'])<th class="py-2 pr-4">DPH</th>@endunless<th class="py-2 text-right">Celkem</th></tr></thead>
                        <tbody class="divide-y divide-slate-200">
                            @foreach($document['items'] as $item)
                                <tr><td class="py-3 pr-4">{{ $item['description'] }}</td><td class="py-3 pr-4 text-right">{{ $item['quantity'] }} {{ $item['unit'] }}</td><td class="py-3 pr-4 text-right">{{ $item['unit_price'] }} {{ $document['currency'] }}</td><td class="py-3 pr-4 text-right">{{ $item['discount'] }} {{ $document['currency'] }}</td>@unless($document['is_non_payer'])<td class="py-3 pr-4">{{ $item['tax_label'] }} · {{ $item['vat_amount'] }} {{ $document['currency'] }}</td>@endunless<td class="py-3 text-right font-bold">{{ $item['total'] }} {{ $document['currency'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @unless($document['is_non_payer'])
                <section class="border-t border-slate-200 p-6"><h2 class="font-bold">Souhrn DPH</h2><div class="mt-3 space-y-2 text-sm">@foreach($document['vat_summaries'] as $summary)<div class="flex flex-wrap justify-between gap-3"><span>{{ $summary['tax_label'] }}</span><span>Základ {{ $summary['tax_base'] }} · DPH {{ $summary['vat_amount'] }} · Celkem {{ $summary['total'] }} {{ $document['currency'] }}</span></div>@endforeach</div></section>
            @endunless

            @if($document['qr']['available'])
                <section class="border-t border-slate-200 p-6">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><h2 class="font-bold">QR Platba</h2><p class="mt-1 text-sm text-slate-600">Naskenujte v bankovní aplikaci.</p></div>
                        <img class="h-40 w-40" src="{{ $document['qr']['svg_data_uri'] }}" alt="QR kód pro zaplacení faktury">
                    </div>
                </section>
            @endif

            <footer class="grid gap-6 border-t border-slate-200 bg-slate-50 p-6 md:grid-cols-2">
                <div class="text-sm">@if($document['bank_account'])<p><strong>Bankovní účet:</strong> {{ $document['bank_account']['domestic'] }}</p>@if($document['bank_account']['iban'])<p>IBAN {{ $document['bank_account']['iban'] }}</p>@endif @endif @if($document['note'])<p class="mt-3 whitespace-pre-line">{{ $document['note'] }}</p>@endif</div>
                <dl class="space-y-2 text-sm md:ml-auto md:min-w-72">@if($document['totals']['has_discount'])<div class="flex justify-between gap-4"><dt>Sleva</dt><dd>{{ $document['totals']['discount_total'] }} {{ $document['currency'] }}</dd></div>@endif @unless($document['is_non_payer'])<div class="flex justify-between gap-4"><dt>DPH</dt><dd>{{ $document['totals']['vat_total'] }} {{ $document['currency'] }}</dd></div>@endunless @if($document['totals']['has_rounding'])<div class="flex justify-between gap-4"><dt>Zaokrouhlení</dt><dd>{{ $document['totals']['rounding_adjustment'] }} {{ $document['currency'] }}</dd></div>@endif<div class="flex justify-between gap-5 border-t border-slate-300 pt-3 text-xl font-bold"><dt>Celkem</dt><dd>{{ $document['totals']['grand_total'] }} {{ $document['currency'] }}</dd></div></dl>
            </footer>
        </article>
        <p class="mt-4 text-center text-xs text-slate-500">Veřejný odkaz umožňuje pouze čtení vystavené faktury.</p>
    </main>
</body>
</html>
