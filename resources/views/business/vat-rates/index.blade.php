<x-layouts.app>
    <x-slot:title>Sazby DPH</x-slot:title>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><h1 class="text-2xl font-bold">Sazby DPH</h1><p class="mt-1 text-sm text-slate-600">Časově platné sazby a daňové režimy aktivního subjektu.</p></div>
        @can('create', App\Models\Business\VatRate::class)<a class="button-primary" href="{{ route('vat-rates.create') }}">Nová sazba</a>@endcan
    </div>
    <div class="mt-5">@include('business.vat-rates._warning')</div>

    <form class="mt-5 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-5" method="GET">
        <select class="form-input" name="status">
            @foreach (['current' => 'Nejedná se o archiv', 'active' => 'Aktivní', 'inactive' => 'Neaktivní', 'archived' => 'Archivované', 'all' => 'Všechny'] as $value => $label)
                <option value="{{ $value }}" @selected(request('status', 'current') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select class="form-input" name="tax_type"><option value="">Všechny režimy</option>@foreach ($taxTypes as $value => $label)<option value="{{ $value }}" @selected(request('tax_type') === $value)>{{ $label }}</option>@endforeach</select>
        <input class="form-input" type="date" name="valid_on" value="{{ request('valid_on') }}" aria-label="Platné k datu">
        <select class="form-input" name="sort"><option value="sort_order">Pořadí</option><option value="name" @selected(request('sort') === 'name')>Název</option><option value="valid_from" @selected(request('sort') === 'valid_from')>Platnost</option></select>
        <button class="button-secondary" type="submit">Filtrovat</button>
    </form>

    <div class="mt-5 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full text-sm"><thead class="bg-slate-50 text-left"><tr><th class="p-3">Název</th><th class="p-3">Režim</th><th class="p-3">Sazba</th><th class="p-3">Platnost</th><th class="p-3">Stav</th><th class="p-3">Akce</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        @forelse ($rates as $rate)
            <tr><td class="p-3"><a class="font-semibold text-blue-700" href="{{ route('vat-rates.show', $rate->uuid) }}">{{ $rate->name }}</a><span class="block text-xs text-slate-500">{{ $rate->code }}</span></td><td class="p-3">{{ $rate->tax_type->label() }}</td><td class="p-3 tabular-nums">{{ $rate->percentage !== null ? $rate->percentage.' %' : 'Bez sazby' }}</td><td class="p-3">{{ $rate->valid_from->format('d. m. Y') }} – {{ $rate->valid_to?->format('d. m. Y') ?? 'bez konce' }}</td><td class="p-3">@if($rate->isArchived()) Archivovaná @elseif($rate->is_active) Aktivní @else Neaktivní @endif @if($rate->defaultAssignment)<span class="block font-semibold text-blue-700">Výchozí pro prodej</span>@endif</td><td class="p-3">@include('business.vat-rates._actions')</td></tr>
        @empty <tr><td class="p-6 text-center text-slate-500" colspan="6">Žádné sazby neodpovídají filtru.</td></tr>@endforelse
        </tbody></table>
    </div>
    <div class="mt-5">{{ $rates->links() }}</div>
</x-layouts.app>
