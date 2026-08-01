<x-layouts.app title="Audit změn">
    <div class="mb-6">
        <p class="text-sm font-medium text-blue-700">Nastavení subjektu</p>
        <h1 class="mt-1 text-2xl font-bold">Audit změn</h1>
        <p class="mt-2 text-sm text-slate-600">Neměnná historie důležitých změn v právě aktivní business databázi.</p>
    </div>

    <form method="GET" action="{{ route('business-audit.index') }}" class="card mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div><label for="date_from">Od data</label><input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}"></div>
        <div><label for="date_to">Do data</label><input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}"></div>
        <div><label for="event">Událost</label><select id="event" name="event"><option value="">Všechny události</option>@foreach($events as $value => $label)<option value="{{ $value }}" @selected(($filters['event'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="auditable_type">Typ entity</label><select id="auditable_type" name="auditable_type"><option value="">Všechny typy</option>@foreach($auditableTypes as $value => $label)<option value="{{ $value }}" @selected(($filters['auditable_type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="actor">Uživatel</label><input id="actor" name="actor" value="{{ $filters['actor'] ?? '' }}" maxlength="100"></div>
        <div><label for="request_id">Request ID</label><input id="request_id" name="request_id" value="{{ $filters['request_id'] ?? '' }}" maxlength="64" class="font-mono"></div>
        <div><label for="sort">Řazení</label><select id="sort" name="sort">@foreach(['occurred_at'=>'Čas','event'=>'Událost','auditable_type'=>'Typ entity','actor_name'=>'Uživatel'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['sort'] ?? 'occurred_at') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="direction">Směr</label><select id="direction" name="direction"><option value="desc" @selected(($filters['direction'] ?? 'desc') === 'desc')>Od nejnovějších</option><option value="asc" @selected(($filters['direction'] ?? '') === 'asc')>Od nejstarších</option></select></div>
        <div class="flex items-end gap-2 xl:col-span-4"><button class="button-primary" type="submit">Filtrovat</button><a class="button-secondary" href="{{ route('business-audit.index') }}">Zrušit filtry</a></div>
    </form>

    @if($audits->isEmpty())
        <section class="card text-center"><h2 class="font-bold">Žádné auditní záznamy</h2><p class="mt-2 text-sm text-slate-600">Pro zvolené filtry nebyla nalezena žádná událost.</p></section>
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="px-4 py-3">Čas</th><th class="px-4 py-3">Událost</th><th class="px-4 py-3">Uživatel</th><th class="px-4 py-3">Entity</th><th class="px-4 py-3">Request ID</th><th class="px-4 py-3"></th></tr></thead>
            <tbody class="divide-y divide-slate-200">@foreach($audits as $audit)<tr><td class="whitespace-nowrap px-4 py-3">{{ $audit->occurred_at->format('j. n. Y H:i:s') }}</td><td class="px-4 py-3 font-semibold">{{ $audit->event->label() }}</td><td class="px-4 py-3">{{ $audit->actor_name ?? 'Systém' }}</td><td class="px-4 py-3">{{ $audit->auditable_type->label() }}@if($audit->auditable_uuid)<span class="mt-1 block font-mono text-xs text-slate-500">{{ $audit->auditable_uuid }}</span>@endif</td><td class="px-4 py-3 font-mono text-xs">{{ $audit->request_id ?? '—' }}</td><td class="px-4 py-3 text-right"><a class="button-secondary" href="{{ route('business-audit.show', $audit->uuid) }}">Detail</a></td></tr>@endforeach</tbody></table>
        </div>
        <div class="mt-5">{{ $audits->links() }}</div>
    @endif
</x-layouts.app>
