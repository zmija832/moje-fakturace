<x-layouts.app title="Duplikovat fakturu">
    @php
        $canCreateClient = auth()->user()?->can('create', \App\Models\Business\Client::class);
        $revision = $source->issuedRevision;
        $config = [
            'searchUrl' => route('clients.search'),
            'clientStoreUrl' => $canCreateClient ? route('clients.store') : null,
            'aresLookupUrl' => $canCreateClient ? route('clients.ares.lookup') : null,
            'csrf' => csrf_token(),
            'initialMode' => old('client_mode', 'original'),
            'initialClient' => null,
        ];
    @endphp
    <div class="mx-auto max-w-5xl" x-data="duplicateClientPicker({{ Illuminate\Support\Js::from($config) }})">
        <div class="mb-6">
            <p class="text-sm font-medium text-blue-700">Faktury</p>
            <h1 class="mt-1 text-2xl font-bold">Duplikovat {{ $source->document_number }}</h1>
            <p class="mt-2 text-sm text-slate-600">Zvolte, zda má nový koncept zachovat původního klienta, nebo použít jiného.</p>
        </div>

        <form method="POST" action="{{ route('invoices.duplicate', $source->uuid) }}" class="space-y-6">
            @csrf
            <section class="card space-y-4" aria-labelledby="duplicate-client-title">
                <div>
                    <h2 id="duplicate-client-title" class="text-lg font-bold">Klient nové faktury</h2>
                    <p class="mt-1 text-sm text-slate-600">Původní klient zůstane vybraný, pokud režim nezměníte.</p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="cursor-pointer rounded-xl border p-4 transition" :class="clientMode === 'original' ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500' : 'border-slate-200 bg-white hover:border-slate-300'">
                        <span class="flex items-start gap-3">
                            <input type="radio" name="client_mode" value="original" x-model="clientMode" class="mt-1" @checked(old('client_mode', 'original') === 'original')>
                            <span>
                                <span class="block font-semibold">Ponechat původního klienta</span>
                                <span class="mt-1 block text-sm text-slate-600">{{ $revision->customerSnapshot->display_name }}</span>
                                <span class="block text-xs text-slate-500">
                                    {{ collect([
                                        filled($revision->customerSnapshot->registration_number) ? 'IČO '.$revision->customerSnapshot->registration_number : null,
                                        $revision->customerSnapshot->email,
                                    ])->filter()->join(' · ') }}
                                </span>
                            </span>
                        </span>
                    </label>
                    <label class="cursor-pointer rounded-xl border p-4 transition" :class="clientMode === 'selected' ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500' : 'border-slate-200 bg-white hover:border-slate-300'">
                        <span class="flex items-start gap-3">
                            <input type="radio" name="client_mode" value="selected" x-model="clientMode" @change="openPicker" class="mt-1" @checked(old('client_mode') === 'selected')>
                            <span>
                                <span class="block font-semibold">Vybrat jiného klienta</span>
                                <span class="mt-1 block text-sm text-slate-600">Vyhledejte aktivního klienta nebo vytvořte nového.</span>
                            </span>
                        </span>
                    </label>
                </div>

                <div x-cloak x-show="clientMode === 'selected'" class="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <input type="hidden" name="selected_client_uuid" :value="selected?.uuid ?? ''">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="min-w-64 flex-1">
                            <label for="duplicate-client-search">Hledat klienta</label>
                            <input id="duplicate-client-search" x-ref="clientSearch" type="search" x-model="query" @input.debounce.250ms="search" @focus="openPicker" autocomplete="off" placeholder="Hledat mezi všemi klienty podle názvu, IČO nebo e-mailu…">
                        </div>
                        @if($canCreateClient)
                            <button type="button" class="button-secondary" @click="openQuickClient">Nový klient</button>
                        @endif
                    </div>
                    <div x-show="selected" class="rounded-xl border border-blue-200 bg-white px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Vybraný klient</p>
                        <p class="mt-1 font-semibold" x-text="selected?.display_name"></p>
                        <p class="text-sm text-slate-600" x-text="clientMeta(selected)"></p>
                    </div>
                    <div x-show="open && query.trim() !== ''" class="max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <template x-for="client in availableClients()" :key="client.uuid">
                            <button type="button" class="block w-full border-b border-slate-100 px-4 py-3 text-left last:border-0 hover:bg-slate-50" @click="select(client)">
                                <span class="block font-semibold" x-text="client.display_name"></span>
                                <span class="block text-xs text-slate-500" x-text="clientMeta(client)"></span>
                            </button>
                        </template>
                        <p x-show="!loading && query.trim() !== '' && availableClients().length === 0" class="p-4 text-sm text-slate-500">Žádný aktivní klient neodpovídá hledání.</p>
                        <p x-show="loading" class="p-4 text-sm text-slate-500">Načítám…</p>
                    </div>
                    @error('selected_client_uuid')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                @error('client_mode')<p class="field-error">{{ $message }}</p>@enderror
            </section>

            <section class="card" aria-labelledby="duplicate-summary-title">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 id="duplicate-summary-title" class="text-lg font-bold">Souhrn duplikované faktury</h2>
                        <p class="mt-1 text-sm text-slate-500">Původní odběratel: {{ $revision->customerSnapshot->display_name }}</p>
                    </div>
                    <p class="text-2xl font-bold tabular-nums">{{ \App\Domain\Invoices\InvoiceDecimal::formatMoney($revision->grand_total, $revision->currency) }}</p>
                </div>
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="text-slate-500">Původní faktura</dt><dd class="font-semibold">{{ $source->document_number }}</dd></div>
                    <div><dt class="text-slate-500">Datum vystavení</dt><dd>{{ $revision->issued_on->format('j. n. Y') }}</dd></div>
                    <div><dt class="text-slate-500">Splatnost</dt><dd>{{ $revision->due_on->format('j. n. Y') }}</dd></div>
                    <div><dt class="text-slate-500">Měna</dt><dd>{{ $revision->currency }}</dd></div>
                </dl>

                <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200">
                    <table class="w-full min-w-[44rem] text-left text-sm">
                        <thead class="bg-slate-50"><tr><th class="px-4 py-3">Položka</th><th class="px-4 py-3 text-right">Množství</th><th class="px-4 py-3 text-right">Jednotková cena</th><th class="px-4 py-3 text-right">Sleva</th><th class="px-4 py-3 text-right">Celkem</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($revision->items as $item)
                                <tr>
                                    <td class="px-4 py-3 font-medium">{{ $item->description }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Domain\Invoices\InvoiceDecimal::formatQuantity($item->quantity) }} {{ $item->unit }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Domain\Invoices\InvoiceDecimal::formatMoney($item->unit_price, $revision->currency) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Domain\Invoices\InvoiceDecimal::compare($item->line_discount_amount, '0') === 0 ? '—' : $item->discount_type->label().' · '.\App\Domain\Invoices\InvoiceDecimal::formatMoney($item->line_discount_amount, $revision->currency) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::formatMoney($item->line_total_amount, $revision->currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if(\App\Domain\Invoices\InvoiceDecimal::compare($revision->invoice_discount_amount, '0') > 0)
                    <div class="mt-4 flex justify-between gap-4 text-sm"><span class="text-slate-600">Celková sleva · {{ $revision->invoice_discount_type->label() }}</span><strong>{{ \App\Domain\Invoices\InvoiceDecimal::formatMoney($revision->invoice_discount_amount, $revision->currency) }}</strong></div>
                @endif
                @if(filled($revision->note))
                    <div class="mt-5 rounded-xl bg-slate-50 p-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-500">Poznámka</p><p class="mt-1 whitespace-pre-line text-sm">{{ $revision->note }}</p></div>
                @endif
                <div class="mt-5 flex items-center justify-between border-t border-slate-200 pt-4"><span class="font-semibold">Celkem</span><strong class="text-xl tabular-nums">{{ \App\Domain\Invoices\InvoiceDecimal::formatMoney($revision->grand_total, $revision->currency) }}</strong></div>
            </section>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <a class="button-secondary" href="{{ route('invoices.show', $source->uuid) }}">Zrušit</a>
                <button class="button-primary" type="submit" :disabled="clientMode === 'selected' && !selected">Vytvořit koncept</button>
            </div>
        </form>

        @if($canCreateClient)
            <x-clients.quick-create-modal :client-types="$clientTypes" :countries="$countries" />
        @endif
    </div>
</x-layouts.app>
