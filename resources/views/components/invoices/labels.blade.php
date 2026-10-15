@props(['invoice', 'availableLabels' => []])
<section class="card mb-6" aria-labelledby="invoice-labels-title">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h2 id="invoice-labels-title" class="text-lg font-bold">Štítky</h2><p class="mt-1 text-sm text-slate-600">Interní označení se nezobrazuje zákazníkovi ani na PDF.</p></div>
        @can('updateAny', \App\Models\Business\Invoice::class)
            <a class="text-sm font-semibold text-blue-700 hover:underline" href="{{ route('invoice-labels.index') }}">Spravovat štítky</a>
        @endcan
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-2">
        @foreach($invoice->labels as $label)
            <span class="inline-flex items-center gap-1">
                <x-invoices.label-badge :label="$label" />
                @can('updateAny', \App\Models\Business\Invoice::class)
                    <form method="POST" action="{{ route('invoices.labels.detach', [$invoice->uuid, $label->uuid]) }}">@csrf @method('DELETE')<button type="submit" class="rounded px-1 text-sm text-slate-500 hover:bg-slate-100" aria-label="Odebrat štítek {{ $label->name }}">×</button></form>
                @endcan
            </span>
        @endforeach
        @if($invoice->labels->isEmpty())<span class="text-sm text-slate-500">Bez štítků</span>@endif
    </div>
    @can('updateAny', \App\Models\Business\Invoice::class)
        @if(collect($availableLabels)->isNotEmpty())
            <form method="POST" action="{{ route('invoices.labels.attach', $invoice->uuid) }}" class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end">@csrf
                <div class="min-w-0 flex-1"><label for="label_uuid">Přidat štítek</label><select id="label_uuid" name="label_uuid" required><option value="">Vyberte štítek</option>@foreach($availableLabels as $label)<option value="{{ $label->uuid }}">{{ $label->name }}</option>@endforeach</select></div>
                <button class="button-secondary shrink-0" type="submit">Přidat</button>
            </form>
        @endif
    @endcan
</section>
