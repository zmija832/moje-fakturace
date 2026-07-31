@if ($canManage && ! $sequence->isArchived())
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('document-sequences.edit', $sequence->uuid) }}" class="button-secondary">Upravit</a>

        @if ($sequence->is_active && ! $sequence->defaultAssignment)
            <form method="POST" action="{{ route('document-sequences.set-default', $sequence->uuid) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="button-secondary">Nastavit jako výchozí</button>
            </form>
        @endif

        @if ($sequence->is_active)
            <form method="POST" action="{{ route('document-sequences.deactivate', $sequence->uuid) }}"
                  onsubmit="return confirm('Deaktivovat řadu? Případná výchozí vazba bude odstraněna.')">
                @csrf
                @method('PATCH')
                <button type="submit" class="button-secondary">Deaktivovat</button>
            </form>
        @else
            <form method="POST" action="{{ route('document-sequences.activate', $sequence->uuid) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="button-secondary">Aktivovat</button>
            </form>
        @endif

        <form method="POST" action="{{ route('document-sequences.archive', $sequence->uuid) }}"
              onsubmit="return confirm('Archivovat řadu? Archivace je nevratná a přidělená čísla zůstanou zachována.')">
            @csrf
            @method('PATCH')
            <button type="submit" class="button-secondary text-red-700">Archivovat</button>
        </form>
    </div>
@endif
