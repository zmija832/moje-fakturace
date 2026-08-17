@props(['invoice'])

@php($isDraft = $invoice->status === \App\Enums\InvoiceStatus::Draft)
<div class="flex flex-wrap justify-end gap-x-3 gap-y-2 text-sm">
    <a class="font-semibold text-blue-700 hover:underline" href="{{ route('invoices.show', $invoice->uuid) }}">Detail</a>
    @can('restore', $invoice)
        <form method="POST" action="{{ route('invoices.restore', $invoice->uuid) }}">@csrf @method('PATCH')<button class="font-semibold text-emerald-700 hover:underline" type="submit">Obnovit</button></form>
    @endcan
    @if($invoice->archived_at === null && $isDraft)
        @can('update', $invoice)<a class="font-semibold text-blue-700 hover:underline" href="{{ route('invoices.edit', $invoice->uuid) }}">Upravit</a>@endcan
    @elseif($invoice->archived_at === null)
        @can('downloadPdf', $invoice)
            @if($invoice->currentPdfDocument() !== null)<a class="font-semibold text-blue-700 hover:underline" href="{{ route('invoices.pdf.download', $invoice->uuid) }}">PDF</a>@endif
        @endcan
        @can('create', \App\Models\Business\Invoice::class)
            <form method="POST" action="{{ route('invoices.duplicate', $invoice->uuid) }}">@csrf<button class="font-semibold text-blue-700 hover:underline" type="submit">Duplikovat</button></form>
        @endcan
        @can('managePublicLink', $invoice)<a class="font-semibold text-blue-700 hover:underline" href="{{ route('invoices.show', $invoice->uuid) }}#invoice-public-link">Webfaktura</a>@endcan
    @endif
    @can('archive', $invoice)
        <form method="POST" action="{{ route('invoices.archive', $invoice->uuid) }}" onsubmit="return confirm('Doklad se pouze skryje z aktivního seznamu. Historie zůstane zachována. Pokračovat?')">
            @csrf @method('PATCH')<button class="font-semibold text-slate-700 hover:underline" type="submit">Archivovat</button>
        </form>
    @endcan
</div>