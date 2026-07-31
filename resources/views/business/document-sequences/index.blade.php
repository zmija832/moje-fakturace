<x-layouts.app title="Číselné řady">
    @php $canManage = auth()->user()->can('updateAny', \App\Models\Business\DocumentSequence::class); @endphp
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div><p class="text-sm font-medium text-blue-700">Nastavení subjektu</p><h1 class="mt-1 text-2xl font-bold">Číselné řady</h1><p class="mt-2 text-sm text-slate-600">Konfigurace budoucího číslování; skutečné doklady zatím nejsou implementované.</p></div>
        @if ($canManage) <a href="{{ route('document-sequences.create') }}" class="button-primary">Nová číselná řada</a> @endif
    </div>

    @if ($sequences->isEmpty())
        <section class="card text-center"><h2 class="font-bold">Zatím není vytvořena žádná řada</h2><p class="mt-2 text-sm text-slate-600">Vytvořte první konfiguraci pro některý typ dokladu.</p></section>
    @else
        <div class="space-y-4">
            @foreach ($sequences as $sequence)
                <article class="card {{ $sequence->isArchived() ? 'bg-slate-100 text-slate-600' : '' }}">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-bold"><a class="hover:text-blue-700" href="{{ route('document-sequences.show', $sequence->uuid) }}">{{ $sequence->name }}</a></h2>
                                @if ($sequence->defaultAssignment) <span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800">Výchozí</span> @endif
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $sequence->isArchived() ? 'bg-slate-300' : ($sequence->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200') }}">{{ $sequence->isArchived() ? 'Archivovaná' : ($sequence->is_active ? 'Aktivní' : 'Neaktivní') }}</span>
                            </div>
                            <p class="mt-1 text-sm">{{ $sequence->document_type->label() }}</p>
                            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                <div><dt class="text-slate-500">Formát / další očekávané</dt><dd class="break-all font-mono font-semibold">{{ $previews[$sequence->uuid] }}</dd></div>
                                <div><dt class="text-slate-500">Resetování</dt><dd>{{ $sequence->reset_period->label() }}</dd></div>
                                <div><dt class="text-slate-500">Přiděleno</dt><dd>{{ $sequence->allocations_count }}</dd></div>
                                <div><dt class="text-slate-500">Pořadí</dt><dd>{{ $sequence->sort_order }}</dd></div>
                            </dl>
                        </div>
                        <div class="shrink-0">@include('business.document-sequences._actions', compact('sequence', 'canManage'))</div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</x-layouts.app>
