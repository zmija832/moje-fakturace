@props(['revision'])

<section class="card mt-6">
    <h2 class="text-lg font-bold">Položky</h2>
    <div class="mt-4 space-y-3 xl:space-y-0 xl:divide-y xl:divide-slate-200 xl:rounded-xl xl:border xl:border-slate-200">
        @foreach($revision->items as $item)
            @php
                $isNonPayer = $item->vatSnapshot->tax_type === \App\Enums\VatTaxType::NonPayer;
                $discountAmount = \App\Domain\Invoices\InvoiceDecimal::add($item->line_discount_amount, $item->invoice_discount_amount);
            @endphp
            <article class="rounded-xl border border-slate-200 p-3 xl:rounded-none xl:border-0">
                @if($isNonPayer)
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(16rem,2fr)_minmax(12rem,1fr)_8rem_10rem] xl:items-center">
                        <div>
                            <h3 class="font-semibold">{{ $item->position }}. {{ $item->description }}</h3>
                            <p class="mt-1 text-xs text-slate-500">Neplátce DPH</p>
                        </div>
                        <div class="text-sm">
                            <span class="text-slate-500 xl:sr-only">Množství × jednotková cena</span>
                            <span class="block">{{ \App\Domain\Invoices\InvoiceDecimal::format($item->quantity, 4) }} {{ $item->unit }} × {{ \App\Domain\Invoices\InvoiceDecimal::format($item->unit_price, 4) }} {{ $revision->currency }}</span>
                        </div>
                        <div class="text-sm">
                            <span class="text-slate-500 xl:sr-only">Sleva</span>
                            <span class="block">{{ \App\Domain\Invoices\InvoiceDecimal::compare($discountAmount, '0') === 0 ? '—' : \App\Domain\Invoices\InvoiceDecimal::format($discountAmount).' '.$revision->currency }}</span>
                        </div>
                        <div class="text-left sm:text-right">
                            <span class="text-xs text-slate-500 xl:sr-only">Výsledná částka</span>
                            <strong class="block tabular-nums">{{ \App\Domain\Invoices\InvoiceDecimal::format($item->line_total_amount) }} {{ $revision->currency }}</strong>
                        </div>
                    </div>
                @else
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(14rem,2fr)_minmax(11rem,1fr)_7rem_8rem_9rem_8rem_9rem] xl:items-center">
                        <h3 class="font-semibold">{{ $item->position }}. {{ $item->description }}</h3>
                        <div class="text-sm"><span class="text-slate-500 xl:sr-only">Množství × jednotková cena</span><span class="block">{{ \App\Domain\Invoices\InvoiceDecimal::format($item->quantity, 4) }} {{ $item->unit }} × {{ \App\Domain\Invoices\InvoiceDecimal::format($item->unit_price, 4) }} {{ $revision->currency }}</span></div>
                        <div class="text-sm"><span class="text-slate-500 xl:sr-only">Sleva</span><span class="block">{{ \App\Domain\Invoices\InvoiceDecimal::compare($discountAmount, '0') === 0 ? '—' : \App\Domain\Invoices\InvoiceDecimal::format($discountAmount).' '.$revision->currency }}</span></div>
                        <div class="text-sm"><span class="text-slate-500 xl:sr-only">Základ</span><span class="block">{{ \App\Domain\Invoices\InvoiceDecimal::format($item->line_net_amount) }}</span></div>
                        <div class="text-sm"><span class="text-slate-500 xl:sr-only">Sazba DPH</span><span class="block">{{ $item->vatSnapshot->name }}@if($item->vatSnapshot->percentage !== null) · {{ \App\Domain\Invoices\InvoiceDecimal::format($item->vatSnapshot->percentage) }} % @endif</span></div>
                        <div class="text-sm"><span class="text-slate-500 xl:sr-only">DPH</span><span class="block">{{ \App\Domain\Invoices\InvoiceDecimal::format($item->vat_amount) }}</span></div>
                        <div class="text-left sm:text-right"><span class="text-xs text-slate-500 xl:sr-only">Celkem</span><strong class="block tabular-nums">{{ \App\Domain\Invoices\InvoiceDecimal::format($item->line_total_amount) }} {{ $revision->currency }}</strong></div>
                    </div>
                @endif
            </article>
        @endforeach
    </div>
</section>
