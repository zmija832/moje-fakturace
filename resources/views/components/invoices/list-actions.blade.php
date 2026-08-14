@props(['invoice'])

@php($isDraft = $invoice->status === \App\Enums\InvoiceStatus::Draft)
<div class="flex flex-wrap justify-end gap-x-3 gap-y-2 text-sm">
    <a class="font-semibold text-blue-700 hover:underline" href="{{ route('invoices.show', $invoice->uuid) }}">Detail</a>
    @if($isDraft)
        @can('update', $invoice)
            <a class="font-semibold text-blue-700 hover:underline" href="{{ route('invoices.edit', $invoice->uuid) }}">Upravit</a>
        @endcan
        @can('archive', $invoice)
            <form method="POST" action="{{ route('invoices.archive', $invoice->uuid) }}" onsubmit="return confirm('Koncept se skryje z běžného seznamu. Revize a audit zůstanou zachované. Pokračovat?')">
                @csrf
                @method('PATCH')
                <button class="font-semibold text-red-700 hover:underline" type="submit">Archivovat</button>
            </form>
        @endcan
    @else
        @can('downloadPdf', $invoice)
            @if($invoice->documents->isNotEmpty())
                <a class="font-semibold text-blue-700 hover:underline" href="{{ route('invoices.pdf.download', $invoice->uuid) }}">PDF</a>
            @endif
        @endcan
        @can('create', \App\Models\Business\Invoice::class)
            <form method="POST" action="{{ route('invoices.duplicate', $invoice->uuid) }}">
                @csrf
                <button class="font-semibold text-blue-700 hover:underline" type="submit">Duplikovat</button>
            </form>
        @endcan
        @can('managePublicLink', $invoice)
            <a class="font-semibold text-blue-700 hover:underline" href="{{ route('invoices.show', $invoice->uuid) }}#invoice-public-link">Webfaktura</a>
        @endcan
    @endif
</div>