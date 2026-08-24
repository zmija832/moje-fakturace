@props(['invoice'])

<div class="flex justify-end text-sm">
    <a class="font-semibold text-blue-700 hover:underline" href="{{ route('invoices.show', $invoice->uuid) }}">Detail</a>
</div>
