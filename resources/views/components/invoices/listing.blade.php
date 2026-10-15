@props(['invoices'])
<section x-data="invoiceQuickPreview()" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="hidden lg:block">
        <table class="w-full table-fixed text-left text-sm"><colgroup><col class="w-[16%]"><col class="w-[21%]"><col class="w-[22%]"><col class="w-[11%]"><col class="w-[13%]"><col class="w-[10%]"><col class="w-[7%]"></colgroup><thead class="bg-slate-50"><tr><th class="px-3 py-3">Doklad</th><th class="px-3 py-3">Klient</th><th class="px-3 py-3">Data</th><th class="px-3 py-3 text-right">Celkem</th><th class="px-3 py-3 text-right">Úhrada</th><th class="px-3 py-3">Stav</th><th class="px-3 py-3"></th></tr></thead><tbody class="divide-y divide-slate-200">@foreach($invoices as $invoice)<x-invoices.row :invoice="$invoice" />@endforeach</tbody></table>
    </div>
    <div class="divide-y divide-slate-200 lg:hidden">@foreach($invoices as $invoice)<x-invoices.card :invoice="$invoice" />@endforeach</div>

    <div x-cloak x-show="isOpen" class="fixed inset-0 z-50 flex items-end bg-slate-950/45 p-0 lg:items-center lg:justify-center lg:p-6" role="dialog" aria-modal="true" :aria-label="`Rychlý náhled ${title}`" @keydown.escape.window="closePreview" @click.self="closePreview">
        <div class="max-h-[90vh] w-full overflow-y-auto rounded-t-2xl bg-white shadow-2xl lg:max-w-3xl lg:rounded-2xl" @mouseenter="cancelClosePreview" @mouseleave="scheduleClosePreview">
            <div class="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4">
                <h2 class="font-bold" x-text="`Rychlý náhled ${title}`"></h2>
                <button x-ref="previewClose" type="button" class="button-secondary px-3" aria-label="Zavřít náhled" @click="closePreview">×</button>
            </div>
            <div x-show="loading" class="p-8 text-center text-sm text-slate-500">Načítám náhled…</div>
            <div x-show="error" class="p-8 text-center text-sm text-red-700" x-text="error"></div>
            <div x-show="html" x-html="html"></div>
        </div>
    </div>
</section>
