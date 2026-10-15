@props(['invoice'])

<div class="flex flex-col items-end gap-1 text-sm">
    <a class="font-semibold text-blue-700 hover:underline" href="{{ route('invoices.show', $invoice->uuid) }}">Detail</a>
    <button
        type="button"
        class="text-slate-600 hover:text-blue-700 hover:underline"
        @mouseenter="schedulePreview({{ Illuminate\Support\Js::from(route('invoices.quick-preview', $invoice->uuid)) }}, {{ Illuminate\Support\Js::from($invoice->document_number ?? 'Návrh') }}, $event.currentTarget)"
        @mouseleave="scheduleClosePreview"
        @focus="schedulePreview({{ Illuminate\Support\Js::from(route('invoices.quick-preview', $invoice->uuid)) }}, {{ Illuminate\Support\Js::from($invoice->document_number ?? 'Návrh') }}, $event.currentTarget)"
        @blur="scheduleClosePreview"
        @click="openPreview({{ Illuminate\Support\Js::from(route('invoices.quick-preview', $invoice->uuid)) }}, {{ Illuminate\Support\Js::from($invoice->document_number ?? 'Návrh') }}, $event.currentTarget)"
    >Náhled</button>
</div>
