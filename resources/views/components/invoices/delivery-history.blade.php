@props(['invoice'])

<section class="card mt-6">
    <div>
        <h2 class="text-lg font-bold">Dokumenty</h2>
        <p class="text-sm text-slate-600">Neměnná PDF uložená mimo veřejný web.</p>
    </div>
    @if($invoice->documents->isEmpty())
        <p class="mt-4 text-sm text-slate-600">PDF zatím nebylo vygenerováno.</p>
    @else
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr><th class="p-2">Vygenerováno</th><th class="p-2">Soubor</th><th class="p-2">Velikost</th><th class="p-2">Šablona</th><th class="p-2"></th></tr></thead>
                <tbody>
                    @foreach($invoice->documents as $document)
                        <tr class="border-t border-slate-200">
                            <td class="p-2">{{ $document->generated_at->format('j. n. Y H:i') }}</td>
                            <td class="p-2">{{ $document->original_filename }}</td>
                            <td class="p-2">{{ intdiv($document->size_bytes + 1023, 1024) }} kB</td>
                            <td class="p-2">{{ $document->template_version }}</td>
                            <td class="p-2 text-right"><a class="button-secondary" href="{{ route('invoices.pdf.download-version', [$invoice->uuid, $document->uuid]) }}">Stáhnout</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<section id="invoice-delivery-history" class="card mt-6 scroll-mt-6">
    <div>
        <h2 class="text-lg font-bold">Odeslání</h2>
        <p class="text-sm text-slate-600">Historie skutečných pokusů; záznamy se nemažou. Přijetí poštovním serverem ještě nepotvrzuje doručení do schránky.</p>
    </div>
    @if($invoice->emailDeliveries->isEmpty())
        <p class="mt-4 text-sm text-slate-600">Faktura zatím nebyla odeslána.</p>
    @else
        <div class="mt-4 space-y-3">
            @foreach($invoice->emailDeliveries as $delivery)
                @php($deliveryStatusClass = match($delivery->status) {
                    \App\Enums\InvoiceEmailDeliveryStatus::Sent => 'bg-emerald-100 text-emerald-800',
                    \App\Enums\InvoiceEmailDeliveryStatus::Failed => 'bg-red-100 text-red-800',
                    default => 'bg-amber-100 text-amber-900',
                })
                <article class="rounded-lg border border-slate-200 p-3 text-sm">
                    <div class="flex flex-wrap justify-between gap-3"><strong>{{ $delivery->recipient_email }}</strong><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $deliveryStatusClass }}">{{ $delivery->status->label() }}</span></div>
                    <div class="mt-1">{{ $delivery->subject }}</div>
                    <div class="mt-1 text-xs text-slate-500">Pokus {{ $delivery->attempted_at->format('j. n. Y H:i:s') }}@if($delivery->sent_at) · server přijal {{ $delivery->sent_at->format('j. n. Y H:i:s') }}@endif @if($delivery->failed_at) · selhání {{ $delivery->failed_at->format('j. n. Y H:i:s') }}@endif</div>
                    @if($delivery->failure_message)<p class="mt-2 rounded bg-red-50 p-2 text-red-800">{{ $delivery->failure_message }}</p>@endif
                </article>
            @endforeach
        </div>
    @endif
</section>
