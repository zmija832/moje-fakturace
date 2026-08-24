@props(['invoices'])
<section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="hidden lg:block">
        <table class="w-full table-fixed text-left text-sm"><colgroup><col class="w-[16%]"><col class="w-[21%]"><col class="w-[22%]"><col class="w-[11%]"><col class="w-[13%]"><col class="w-[10%]"><col class="w-[7%]"></colgroup><thead class="bg-slate-50"><tr><th class="px-3 py-3">Doklad</th><th class="px-3 py-3">Klient</th><th class="px-3 py-3">Data</th><th class="px-3 py-3 text-right">Celkem</th><th class="px-3 py-3 text-right">Úhrada</th><th class="px-3 py-3">Stav</th><th class="px-3 py-3"></th></tr></thead><tbody class="divide-y divide-slate-200">@foreach($invoices as $invoice)<x-invoices.row :invoice="$invoice" />@endforeach</tbody></table>
    </div>
    <div class="divide-y divide-slate-200 lg:hidden">@foreach($invoices as $invoice)<x-invoices.card :invoice="$invoice" />@endforeach</div>
</section>
