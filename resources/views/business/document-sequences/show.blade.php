<x-layouts.app :title="$sequence->name">
    @php $canManage = auth()->user()->can('updateAny', \App\Models\Business\DocumentSequence::class); @endphp
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div><p class="text-sm font-medium text-blue-700">Číselná řada</p><h1 class="mt-1 text-2xl font-bold">{{ $sequence->name }}</h1><p class="mt-2 text-sm text-slate-600">{{ $sequence->document_type->label() }}</p></div>
        @include('business.document-sequences._actions', compact('sequence', 'canManage'))
    </div>

    @if ($sequence->isArchived())
        <div class="mb-6 rounded-xl border border-slate-300 bg-slate-100 p-4 font-semibold">Archivovaná řada je pouze ke čtení a nelze z ní přidělovat další čísla.</div>
    @elseif ($sequence->allocations_count > 0)
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Řada již byla použita. Historický formát a čítač nelze změnit; pro nový formát vytvořte novou řadu.</div>
    @endif

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="card"><h2 class="text-lg font-bold">Konfigurace</h2><dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">Prefix</dt><dd class="font-mono">{{ $sequence->prefix !== '' ? $sequence->prefix : '—' }}</dd></div>
            <div><dt class="text-slate-500">Suffix</dt><dd class="font-mono">{{ $sequence->suffix !== '' ? $sequence->suffix : '—' }}</dd></div>
            <div><dt class="text-slate-500">Rok</dt><dd>{{ $sequence->year_format->label() }}</dd></div>
            <div><dt class="text-slate-500">Počet číslic</dt><dd>{{ $sequence->sequence_digits }}</dd></div>
            <div><dt class="text-slate-500">Počáteční číslo</dt><dd>{{ $sequence->start_number }}</dd></div>
            <div><dt class="text-slate-500">Resetování</dt><dd>{{ $sequence->reset_period->label() }}</dd></div>
        </dl></section>
        <section class="card"><h2 class="text-lg font-bold">Stav a použití</h2><dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">Stav</dt><dd>{{ $sequence->isArchived() ? 'Archivovaná' : ($sequence->is_active ? 'Aktivní' : 'Neaktivní') }}</dd></div>
            <div><dt class="text-slate-500">Výchozí</dt><dd>{{ $sequence->defaultAssignment ? 'Ano' : 'Ne' }}</dd></div>
            <div><dt class="text-slate-500">Další očekávané číslo</dt><dd class="break-all font-mono font-semibold">{{ $preview }}</dd></div>
            <div><dt class="text-slate-500">Aktuální perioda</dt><dd>{{ $sequence->current_period ?? 'Bez roční periody' }}</dd></div>
            <div><dt class="text-slate-500">Počet alokací</dt><dd>{{ $sequence->allocations_count }}</dd></div>
            <div><dt class="text-slate-500">Poslední přidělené číslo</dt><dd class="break-all font-mono">{{ $lastAllocation?->formatted_number ?? '—' }}</dd></div>
        </dl></section>
    </div>
    <div class="mt-6"><a href="{{ route('document-sequences.index') }}" class="button-secondary">Zpět na číselné řady</a></div>
</x-layouts.app>
