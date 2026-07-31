@php
    $canManage = auth()->user()->can('updateAny', \App\Models\Business\Client::class);
@endphp

<div class="flex flex-wrap gap-2">
    <a href="{{ route('clients.show', $client->uuid) }}" class="button-secondary">Detail</a>

    @if ($canManage && ! $client->isArchived())
        <a href="{{ route('clients.edit', $client->uuid) }}" class="button-secondary">Upravit</a>

        @if ($client->is_active)
            <form method="POST" action="{{ route('clients.deactivate', $client->uuid) }}">
                @csrf
                @method('PATCH')
                <button class="button-secondary" type="submit">Deaktivovat</button>
            </form>
        @else
            <form method="POST" action="{{ route('clients.activate', $client->uuid) }}">
                @csrf
                @method('PATCH')
                <button class="button-secondary" type="submit">Aktivovat</button>
            </form>
        @endif

        <form method="POST" action="{{ route('clients.archive', $client->uuid) }}"
              onsubmit="return confirm('Archivace je jednosměrná. Opravdu chcete klienta archivovat?')">
            @csrf
            @method('PATCH')
            <button class="button-secondary text-red-700" type="submit">Archivovat</button>
        </form>
    @endif
</div>
