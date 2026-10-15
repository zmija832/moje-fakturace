<x-layouts.app title="Štítky faktur">
    <div class="mb-6"><p class="text-sm font-medium text-blue-700">Nastavení</p><h1 class="mt-1 text-2xl font-bold">Štítky faktur</h1><p class="mt-2 text-sm text-slate-600">Interní štítky slouží jen pro orientaci v aplikaci.</p></div>

    @can('create', \App\Models\Business\InvoiceLabel::class)
        <form method="POST" action="{{ route('invoice-labels.store') }}" class="card mb-6">@csrf
            <h2 class="text-lg font-bold">Nový štítek</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_12rem_auto] sm:items-end">
                <div><label for="new-label-name">Název</label><input id="new-label-name" name="name" maxlength="80" required value="{{ old('name') }}">@error('name')<p class="field-error">{{ $message }}</p>@enderror</div>
                <div><label for="new-label-color">Barva</label><select id="new-label-color" name="color" required>@foreach($colors as $value => $name)<option value="{{ $value }}" @selected(old('color', 'blue') === $value)>{{ $name }}</option>@endforeach</select>@error('color')<p class="field-error">{{ $message }}</p>@enderror</div>
                <input type="hidden" name="is_active" value="1"><button class="button-primary" type="submit">Vytvořit</button>
            </div>
        </form>
    @endcan

    <section class="card">
        <h2 class="mb-4 text-lg font-bold">Existující štítky</h2>
        <div class="space-y-3">
            @forelse($labels as $label)
                <form method="POST" action="{{ route('invoice-labels.update', $label->uuid) }}" class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-[1fr_12rem_auto] sm:items-end">@csrf @method('PUT')
                    <div><label for="label-name-{{ $label->uuid }}">Název</label><input id="label-name-{{ $label->uuid }}" name="name" maxlength="80" required value="{{ $label->name }}"><p class="mt-1 text-xs text-slate-500">Přiřazeno {{ $label->invoices_count }} fakturám</p></div>
                    <div><label for="label-color-{{ $label->uuid }}">Barva</label><select id="label-color-{{ $label->uuid }}" name="color" required>@foreach($colors as $value => $name)<option value="{{ $value }}" @selected($label->color->value === $value)>{{ $name }}</option>@endforeach</select></div>
                    <div class="flex items-center gap-3"><label class="flex items-center gap-2 whitespace-nowrap"><input type="checkbox" name="is_active" value="1" @checked($label->is_active)> Aktivní</label><button class="button-secondary" type="submit">Uložit</button></div>
                </form>
            @empty
                <p class="text-sm text-slate-500">Zatím nebyl vytvořen žádný štítek.</p>
            @endforelse
        </div>
    </section>
</x-layouts.app>
