<x-layouts.app title="Přehled">
    <div class="mb-6">
        <p class="text-sm font-medium text-blue-700">Přehled</p>
        <h1 class="mt-1 text-3xl font-bold">Vítejte, {{ $user->name }}</h1>
    </div>

    @if ($activeBusiness)
        <section class="card">
            <p class="text-sm font-medium text-slate-500">Aktivní fakturační subjekt</p>
            <div class="mt-3 flex items-start gap-4">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-xl text-blue-800" aria-hidden="true">
                    {{ $activeBusiness->visual_identifier === 'home-business' ? '⌂' : '▣' }}
                </span>
                <div>
                    <h2 class="text-xl font-bold">{{ $activeBusiness->display_name }}</h2>
                    <p class="mt-1 text-slate-600">IČO {{ $activeBusiness->registration_number }}</p>
                    <p class="mt-4 text-sm text-slate-600">Přehled vychází pouze z vystavených faktur a immutable platebního ledgeru aktivního subjektu.</p>
                </div>
            </div>
        </section>
        @if($paymentOverview->isNotEmpty())
            <section class="mt-6 grid gap-4 md:grid-cols-2">@foreach($paymentOverview as $overview)<article class="card"><h2 class="text-lg font-bold">Úhrady v {{ $overview->currency }}</h2><dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-slate-500">Vystavené</dt><dd class="font-semibold">{{ $overview->invoice_count }}</dd></div><div><dt class="text-slate-500">Neuhrazené</dt><dd class="font-semibold">{{ $overview->unpaid_count }}</dd></div><div><dt class="text-slate-500">Částečně</dt><dd>{{ $overview->partially_paid_count }}</dd></div><div><dt class="text-slate-500">Uhrazené</dt><dd>{{ $overview->paid_count }}</dd></div><div><dt class="text-slate-500">Zbývá uhradit</dt><dd class="font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::format((string) $overview->outstanding_total) }} {{ $overview->currency }}</dd></div><div><dt class="text-slate-500">Přeplatky</dt><dd>{{ \App\Domain\Invoices\InvoiceDecimal::format((string) $overview->overpayment_total) }} {{ $overview->currency }}</dd></div></dl></article>@endforeach</section>
        @endif
    @else
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <h2 class="font-bold text-amber-950">Není přiřazen fakturační subjekt</h2>
            <p class="mt-2 text-sm text-amber-900">Účetní části aplikace jsou bezpečně zablokované. Spusťte instalační příkaz pro nastavení subjektů a oprávnění.</p>
        </section>
    @endif
</x-layouts.app>
