<x-layouts.app title="Klienti">
    @php
        $canManage = auth()->user()->can('create', \App\Models\Business\Client::class);
    @endphp

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-medium text-blue-700">Adresář</p>
            <h1 class="mt-1 text-2xl font-bold">Klienti</h1>
            <p class="mt-2 text-sm text-slate-600">Klienti aktivního fakturačního subjektu.</p>
        </div>
        @if ($canManage)
            <a href="{{ route('clients.create') }}" class="button-primary shrink-0">Přidat klienta</a>
        @endif
    </div>

    @unless ($canManage)
        <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
            Klienty můžete prohlížet; měnit je může pouze administrátor subjektu.
        </div>
    @endunless

    <form method="GET" action="{{ route('clients.index') }}" class="card mb-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="xl:col-span-2">
                <label for="search">Hledat</label>
                <input id="search" name="search" type="search" maxlength="100"
                       value="{{ $filters['search'] ?? '' }}" placeholder="Název, IČO, e-mail nebo město">
            </div>
            <div>
                <label for="type">Typ</label>
                <select id="type" name="type">
                    <option value="all">Všechny typy</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['type'] ?? 'all') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="status">Stav</label>
                <select id="status" name="status">
                    <option value="all_non_archived" @selected(($filters['status'] ?? '') === 'all_non_archived')>Neaktivní i aktivní</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Aktivní</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Neaktivní</option>
                    <option value="archived" @selected(($filters['status'] ?? '') === 'archived')>Archivovaní</option>
                </select>
            </div>
            <div>
                <label for="sort">Řazení</label>
                <select id="sort" name="sort">
                    <option value="display_name" @selected(($filters['sort'] ?? '') === 'display_name')>Podle názvu</option>
                    <option value="city" @selected(($filters['sort'] ?? '') === 'city')>Podle města</option>
                    <option value="created_at" @selected(($filters['sort'] ?? '') === 'created_at')>Podle vytvoření</option>
                </select>
                <input type="hidden" name="direction" value="{{ $filters['direction'] ?? 'asc' }}">
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button class="button-primary" type="submit">Použít filtry</button>
            <a class="button-secondary" href="{{ route('clients.index') }}">Vymazat filtry</a>
        </div>
    </form>

    @if ($clients->isEmpty())
        <section class="card text-center">
            <h2 class="text-lg font-bold">Nebyli nalezeni žádní klienti</h2>
            <p class="mt-2 text-sm text-slate-600">Změňte filtry nebo přidejte prvního klienta.</p>
        </section>
    @else
        <section class="card overflow-hidden p-0">
            <div class="hidden overflow-x-auto lg:block">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                        <tr><th class="px-5 py-3">Klient</th><th class="px-5 py-3">Kontakt</th><th class="px-5 py-3">Stav</th><th class="px-5 py-3">Akce</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($clients as $client)
                            <tr class="{{ $client->isArchived() ? 'bg-slate-100 text-slate-600' : 'bg-white' }}">
                                <td class="px-5 py-4 align-top">
                                    <p class="font-semibold text-slate-900">{{ $client->display_name }}</p>
                                    <p class="mt-1 text-xs">{{ $client->type->label() }}@if($client->registration_number) · IČO {{ $client->registration_number }}@endif</p>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <p>{{ $client->city }}</p>
                                    @if($client->email)<p class="mt-1 break-all text-xs">{{ $client->email }}</p>@endif
                                </td>
                                <td class="px-5 py-4 align-top">
                                    @if ($client->isArchived())
                                        <span class="rounded-full bg-slate-200 px-2.5 py-1 text-xs font-semibold">Archivovaný</span>
                                    @elseif ($client->is_active)
                                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">Aktivní</span>
                                    @else
                                        <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">Neaktivní</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top">@include('business.clients._actions', ['client' => $client])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-slate-200 lg:hidden">
                @foreach ($clients as $client)
                    <article class="p-5 {{ $client->isArchived() ? 'bg-slate-100' : 'bg-white' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div><h2 class="font-bold">{{ $client->display_name }}</h2><p class="mt-1 text-sm">{{ $client->type->label() }} · {{ $client->city }}</p></div>
                            <span class="text-xs font-semibold">{{ $client->isArchived() ? 'Archivovaný' : ($client->is_active ? 'Aktivní' : 'Neaktivní') }}</span>
                        </div>
                        @if($client->registration_number)<p class="mt-3 text-sm">IČO {{ $client->registration_number }}</p>@endif
                        @if($client->email)<p class="mt-1 break-all text-sm">{{ $client->email }}</p>@endif
                        <div class="mt-4">@include('business.clients._actions', ['client' => $client])</div>
                    </article>
                @endforeach
            </div>
        </section>

        <div class="mt-6">{{ $clients->links() }}</div>
    @endif
</x-layouts.app>
