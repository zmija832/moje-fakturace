<x-layouts.app title="Duplikovat fakturu">
    @php
        $canCreateClient = auth()->user()?->can('create', \App\Models\Business\Client::class);
        $config = [
            'searchUrl' => route('clients.search'),
            'clientStoreUrl' => $canCreateClient ? route('clients.store') : null,
            'aresLookupUrl' => $canCreateClient ? route('clients.ares.lookup') : null,
            'csrf' => csrf_token(),
            'initialClient' => $originalClient ? [
                'uuid' => $originalClient->uuid,
                'display_name' => $originalClient->display_name,
                'registration_number' => $originalClient->registration_number,
                'email' => $originalClient->email,
            ] : null,
        ];
    @endphp
    <div class="mx-auto max-w-3xl" x-data="duplicateClientPicker({{ Illuminate\Support\Js::from($config) }})">
        <div class="mb-6">
            <p class="text-sm font-medium text-blue-700">Faktury</p>
            <h1 class="mt-1 text-2xl font-bold">Duplikovat {{ $source->document_number }}</h1>
            <p class="mt-2 text-sm text-slate-600">Vyberte aktivního klienta. Vznikne samostatný koncept; štítky původní faktury se nekopírují.</p>
        </div>

        <form method="POST" action="{{ route('invoices.duplicate', $source->uuid) }}" class="card space-y-5">
            @csrf
            <div class="flex items-end gap-3">
                <div class="min-w-0 flex-1">
                    <label for="duplicate-client-search">Klient *</label>
                    <input id="duplicate-client-search" type="search" x-model="query" @input.debounce.250ms="search" @focus="open = true; search()" autocomplete="off" placeholder="Název, jméno, IČO nebo e-mail">
                </div>
                @if($canCreateClient)
                    <button type="button" class="button-secondary shrink-0" @click="openQuickClient">Nový klient</button>
                @endif
            </div>
            <input type="hidden" name="client_uuid" :value="selected?.uuid ?? ''">
            <div x-show="selected" class="rounded-xl border border-blue-200 bg-blue-50 p-4">
                <p class="font-semibold" x-text="selected?.display_name"></p>
                <p class="mt-1 text-sm text-slate-600" x-text="clientMeta(selected)"></p>
            </div>
            <div x-show="open" class="max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <template x-for="client in clients" :key="client.uuid">
                    <button type="button" class="block w-full border-b border-slate-100 px-4 py-3 text-left last:border-0 hover:bg-slate-50" @click="select(client)">
                        <span class="block font-semibold" x-text="client.display_name"></span>
                        <span class="block text-xs text-slate-500" x-text="clientMeta(client)"></span>
                    </button>
                </template>
                <p x-show="!loading && clients.length === 0" class="p-4 text-sm text-slate-500">Žádný aktivní klient neodpovídá hledání.</p>
                <p x-show="loading" class="p-4 text-sm text-slate-500">Načítám…</p>
            </div>
            @error('client_uuid')<p class="field-error">{{ $message }}</p>@enderror
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <a class="button-secondary" href="{{ route('invoices.show', $source->uuid) }}">Zrušit</a>
                <button class="button-primary" type="submit" :disabled="!selected">Vytvořit koncept</button>
            </div>
        </form>

        @if($canCreateClient)
            <x-clients.quick-create-modal :client-types="$clientTypes" :countries="$countries" />
        @endif
    </div>
</x-layouts.app>
