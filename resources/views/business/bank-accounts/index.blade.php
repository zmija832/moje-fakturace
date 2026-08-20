<x-layouts.app title="Bankovní účty">
    @php
        $canManage = auth()->user()->can('updateAny', \App\Models\Business\BankAccount::class);
    @endphp

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-medium text-blue-700">Nastavení</p>
            <h1 class="mt-1 text-2xl font-bold">Bankovní účty</h1>
            <p class="mt-2 max-w-3xl text-sm text-slate-600">
                Účty právě aktivního fakturačního subjektu. Pro každou měnu může být výchozí pouze jeden aktivní účet.
            </p>
        </div>

        @can('create', \App\Models\Business\BankAccount::class)
            <a href="{{ route('bank-accounts.create') }}" class="button-primary shrink-0">Přidat účet</a>
        @endcan
    </div>

    @unless ($canManage)
        <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
            Bankovní účty můžete prohlížet; upravovat je může pouze administrátor subjektu.
        </div>
    @endunless

    @if ($currentAccounts->isEmpty())
        <section class="card text-center">
            <h2 class="text-lg font-bold">Zatím není uložen žádný účet</h2>
            <p class="mt-2 text-sm text-slate-600">Přidejte první bankovní účet pro tento subjekt.</p>
        </section>
    @else
        <section class="card overflow-hidden p-0">
            <div class="hidden overflow-x-auto lg:block">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-5 py-3">Účet</th>
                            <th class="px-5 py-3">Bankovní údaje</th>
                            <th class="px-5 py-3">Měna a stav</th>
                            <th class="px-5 py-3">Akce</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($currentAccounts as $account)
                            <tr class="{{ $account->is_active ? 'bg-white' : 'bg-slate-50 text-slate-600' }}">
                                <td class="px-5 py-4 align-top">
                                    <p class="font-semibold text-slate-900">{{ $account->name }}</p>
                                    <p class="mt-1 text-xs text-slate-500">Pořadí {{ $account->sort_order }}</p>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    @if ($account->domesticDisplay())
                                        <p class="font-mono">{{ $account->domesticDisplay() }}</p>
                                    @endif
                                    @if ($account->iban)
                                        <p class="mt-1 font-mono text-xs">{{ $account->iban }}</p>
                                    @endif
                                    @if ($account->bic)
                                        <p class="mt-1 text-xs text-slate-500">BIC {{ $account->bic }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <p class="font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::currencyLabel($account->currency) }}</p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @if ($account->defaultAssignment)
                                            <span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800">
                                                Výchozí pro {{ \App\Domain\Invoices\InvoiceDecimal::currencyLabel($account->defaultAssignment->currency) }}
                                            </span>
                                        @endif
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $account->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                            {{ $account->is_active ? 'Aktivní' : 'Neaktivní' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    @include('business.bank-accounts._actions', ['account' => $account, 'canManage' => $canManage])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-slate-200 lg:hidden">
                @foreach ($currentAccounts as $account)
                    <article class="p-5 {{ $account->is_active ? 'bg-white' : 'bg-slate-50' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="font-bold">{{ $account->name }}</h2>
                                <p class="mt-1 text-sm font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::currencyLabel($account->currency) }}</p>
                            </div>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $account->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                {{ $account->is_active ? 'Aktivní' : 'Neaktivní' }}
                            </span>
                        </div>

                        <div class="mt-4 space-y-1 text-sm">
                            @if ($account->domesticDisplay())
                                <p class="font-mono">{{ $account->domesticDisplay() }}</p>
                            @endif
                            @if ($account->iban)
                                <p class="break-all font-mono text-xs">{{ $account->iban }}</p>
                            @endif
                            @if ($account->bic)
                                <p class="text-xs text-slate-500">BIC {{ $account->bic }}</p>
                            @endif
                        </div>

                        @if ($account->defaultAssignment)
                            <p class="mt-3 inline-flex rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800">
                                Výchozí pro {{ \App\Domain\Invoices\InvoiceDecimal::currencyLabel($account->defaultAssignment->currency) }}
                            </p>
                        @endif

                        <div class="mt-4">
                            @include('business.bank-accounts._actions', ['account' => $account, 'canManage' => $canManage])
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @if ($archivedAccounts->isNotEmpty())
        <section class="mt-8">
            <div class="mb-3">
                <h2 class="text-lg font-bold">Archivované účty</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Historické účty nelze aktivovat ani nastavovat jako výchozí.
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($archivedAccounts as $account)
                    <article class="card bg-slate-100 text-slate-600">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-800">{{ $account->name }}</h3>
                                <p class="mt-1 text-sm">{{ \App\Domain\Invoices\InvoiceDecimal::currencyLabel($account->currency) }}</p>
                            </div>
                            <span class="rounded-full bg-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                Archivovaný
                            </span>
                        </div>
                        @if ($account->domesticDisplay())
                            <p class="mt-3 font-mono text-sm">{{ $account->domesticDisplay() }}</p>
                        @endif
                        @if ($account->iban)
                            <p class="mt-1 break-all font-mono text-xs">{{ $account->iban }}</p>
                        @endif
                        <p class="mt-3 text-xs">
                            Archivováno {{ $account->archived_at?->format('j. n. Y H:i') }}
                        </p>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.app>
