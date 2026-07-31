@if ($canManage)
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('bank-accounts.edit', $account->uuid) }}" class="button-secondary">
            Upravit
        </a>

        @if ($account->is_active && ! $account->defaultAssignment)
            <form method="POST" action="{{ route('bank-accounts.set-default', $account->uuid) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="button-secondary">Nastavit jako výchozí</button>
            </form>
        @endif

        @if ($account->is_active)
            <form
                method="POST"
                action="{{ route('bank-accounts.deactivate', $account->uuid) }}"
                onsubmit="return confirm('Deaktivovat účet? Pokud je výchozí, výchozí vazba bude odstraněna.')"
            >
                @csrf
                @method('PATCH')
                <button type="submit" class="button-secondary">Deaktivovat</button>
            </form>
        @else
            <form method="POST" action="{{ route('bank-accounts.activate', $account->uuid) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="button-secondary">Aktivovat</button>
            </form>
        @endif

        <form
            method="POST"
            action="{{ route('bank-accounts.archive', $account->uuid) }}"
            onsubmit="return confirm('Archivovat účet? Účet bude deaktivován a nebude jej možné obnovit v uživatelském rozhraní.')"
        >
            @csrf
            @method('PATCH')
            <button type="submit" class="button-secondary text-red-700">Archivovat</button>
        </form>
    </div>
@endif
