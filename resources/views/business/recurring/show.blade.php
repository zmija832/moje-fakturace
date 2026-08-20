<x-layouts.app :title="$template->name">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-3xl font-bold">{{ $template->name }}</h1>
        <div class="flex gap-2">
            @can('update', $template)
                <a class="btn-secondary" href="{{ route('recurring.edit', $template->uuid) }}">Upravit</a>
                <form method="POST" action="{{ route($template->is_active ? 'recurring.pause' : 'recurring.resume', $template->uuid) }}">@csrf @method('PATCH')<button class="btn-secondary">{{ $template->is_active ? 'Pozastavit' : 'Obnovit' }}</button></form>
                <form method="POST" action="{{ route('recurring.run', $template->uuid) }}">@csrf<button class="btn-primary">Spustit nyní</button></form>
            @endcan
        </div>
    </div>
    <div class="card mt-6">
        <dl class="grid gap-4 md:grid-cols-3">
            <div><dt class="text-slate-500">Další běh</dt><dd>{{ $template->next_run_on->format('d. m. Y') }}</dd></div>
            <div><dt class="text-slate-500">Režim</dt><dd>{{ $template->mode === 'draft' ? 'Koncept' : 'Automaticky vystavit' }}</dd></div>
            <div><dt class="text-slate-500">Stav</dt><dd>{{ $template->is_active ? 'Aktivní' : 'Pozastavená' }}</dd></div>
        </dl>
    </div>
    <div class="card mt-6 overflow-x-auto">
        <h2 class="text-xl font-bold">Historie běhů</h2>
        <table class="mt-3 w-full text-sm">
            <thead><tr><th>Plán</th><th>Spuštěno</th><th>Výsledek</th><th>Faktura</th><th>Chyba</th></tr></thead>
            <tbody>
                @forelse($template->runs as $run)
                    <tr class="border-t">
                        <td>{{ $run->scheduled_on->format('d. m. Y') }}</td>
                        <td>{{ $run->started_at?->format('d. m. Y H:i') }}</td>
                        <td>{{ $run->status }}</td>
                        <td>
                            @if($run->invoice)
                                <a class="text-blue-700" href="{{ route('invoices.show', $run->invoice_uuid) }}">Otevřít</a>
                            @elseif($run->invoice_uuid)
                                <span class="text-slate-500">Faktura smazána</span>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $run->failure_message }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-slate-500">Bez běhů.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
