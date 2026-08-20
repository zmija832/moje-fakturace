<x-layouts.app title="Přehled">
    <div class="mb-6">
        <p class="text-sm font-medium text-blue-700">Přehled</p>
        <h1 class="mt-1 text-3xl font-bold">Vítejte, {{ $user->name }}</h1>
    </div>

    @if($activeBusiness)
        <section class="card">
            <p class="text-sm font-medium text-slate-500">Aktivní fakturační subjekt</p>
            <h2 class="mt-2 text-xl font-bold">{{ $activeBusiness->display_name }}</h2>
            <p class="text-slate-600">IČO {{ $activeBusiness->registration_number }}</p>
        </section>

        @if($overview)
            <section class="mt-6">
                <h2 class="text-xl font-bold">Vyžaduje pozornost</h2>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <a class="card" href="{{ route('invoices.index', ['overdue' => 1]) }}"><strong>{{ $overview['currencies']->sum('overdue_count') }}</strong><span class="ml-2 text-slate-600">faktur po splatnosti</span></a>
                    <a class="card" href="{{ route('recurring.index') }}"><strong>{{ $overview['dueRecurring'] }}</strong><span class="ml-2 text-slate-600">opakovaných faktur čeká</span></a>
                    <div class="card"><strong>{{ $overview['failedAutomation'] }}</strong><span class="ml-2 text-slate-600">selhaných automatizací</span></div>
                </div>
            </section>

            <section class="mt-6 grid gap-4 md:grid-cols-2">
                @foreach($overview['currencies'] as $currency)
                    <article class="card">
                        <h2 class="text-lg font-bold">Úhrady v {{ $currency->currency }}</h2>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div><dt class="text-slate-500">Neuhrazeno celkem · Zbývá uhradit</dt><dd class="font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::formatMoney((string) $currency->outstanding_total, $currency->currency) }}</dd></div>
                            <div><dt class="text-slate-500">Po splatnosti</dt><dd class="font-semibold text-red-700">{{ \App\Domain\Invoices\InvoiceDecimal::formatMoney((string) $currency->overdue_total, $currency->currency) }} ({{ $currency->overdue_count }})</dd></div>
                            <div><dt class="text-slate-500">Vystaveno tento měsíc</dt><dd>{{ \App\Domain\Invoices\InvoiceDecimal::formatMoney((string) (optional($overview['issuedThisMonth']->firstWhere('currency', $currency->currency))->total ?? '0'), $currency->currency) }}</dd></div>
                            <div><dt class="text-slate-500">Uhrazeno tento měsíc</dt><dd>{{ \App\Domain\Invoices\InvoiceDecimal::formatMoney((string) (optional($overview['paidThisMonth']->firstWhere('currency', $currency->currency))->total ?? '0'), $currency->currency) }}</dd></div>
                        </dl>
                    </article>
                @endforeach
            </section>

            <section class="card mt-6">
                <h2 class="text-lg font-bold">Provoz</h2>
                <dl class="mt-3 grid gap-3 md:grid-cols-4">
                    <div><dt>Koncepty</dt><dd class="text-xl font-bold">{{ $overview['draftCount'] }}</dd></div>
                    <div><dt>Aktivní opakované</dt><dd class="text-xl font-bold">{{ $overview['recurringActive'] }}</dd></div>
                    <div><dt>Nejbližší běh</dt><dd>{{ $overview['recurringNext'] ? \Carbon\CarbonImmutable::parse($overview['recurringNext'])->format('d. m. Y') : '—' }}</dd></div>
                    <div><dt>Nové informace o úhradě</dt><dd class="text-xl font-bold">{{ $overview['adminPaidAlerts'] }}</dd></div>
                </dl>
            </section>
        @endif
    @else
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <h2 class="font-bold text-amber-950">Není přiřazen fakturační subjekt</h2>
            <p class="mt-2 text-sm text-amber-900">Účetní části aplikace jsou bezpečně zablokované.</p>
        </section>
    @endif
</x-layouts.app>
