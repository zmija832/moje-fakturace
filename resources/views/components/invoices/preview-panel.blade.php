@props(['isVatPayer' => false])

@if($isVatPayer)
    <section class="rounded-xl border border-slate-200 bg-slate-50 p-4" aria-live="polite">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 class="font-semibold">Rozpis výpočtu</h2><p class="text-xs text-slate-500">Serverový orientační výpočet před uložením.</p></div>
            <button class="button-secondary" type="button" x-show="previewError" @click="refreshPreview(true)" :disabled="loading">Zkusit přepočítat</button>
        </div>
        <p class="mt-3 text-sm text-red-700" x-show="previewError" x-text="previewError"></p>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-4" x-show="preview">
            <div><dt class="text-slate-500">Slevy</dt><dd class="font-semibold" x-text="money(preview?.totals?.discount_total)"></dd></div>
            <div><dt class="text-slate-500">Základ</dt><dd class="font-semibold" x-text="money(preview?.totals?.tax_base_total)"></dd></div>
            <div><dt class="text-slate-500">DPH</dt><dd class="font-semibold" x-text="money(preview?.totals?.vat_total)"></dd></div>
            <div><dt class="text-slate-500">Celkem</dt><dd class="font-bold" x-text="`${money(preview?.totals?.grand_total)} ${currency}`"></dd></div>
        </dl>
    </section>
@else
    <div class="flex justify-end" aria-live="polite" x-show="previewError">
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"><span x-text="previewError"></span><button class="ml-3 font-semibold underline" type="button" @click="refreshPreview(true)" :disabled="loading">Zkusit znovu</button></div>
    </div>
@endif
