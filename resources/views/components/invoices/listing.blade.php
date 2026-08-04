@props(['invoices'])
<section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="hidden overflow-x-auto lg:block">
        <table class="min-w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="px-4 py-3">Doklad</th><th class="px-4 py-3">Klient</th><th class="px-4 py-3">Data</th><th class="px-4 py-3 text-right">Celkem</th><th class="px-4 py-3 text-right">Úhrada</th><th class="px-4 py-3">Stav</th><th class="px-4 py-3"></th></tr></thead><tbody class="divide-y divide-slate-200">@foreach($invoices as $invoice)<x-invoices.row :invoice="$invoice" />@endforeach</tbody></table>
    </div>
    <div class="divide-y divide-slate-200 lg:hidden">@foreach($invoices as $invoice)<x-invoices.card :invoice="$invoice" />@endforeach</div>
</section>
