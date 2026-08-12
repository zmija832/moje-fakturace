@can('updateAny', App\Models\Business\VatRate::class)
    @unless ($rate->isSystemManaged())
    @unless ($rate->isArchived())
        <div class="flex flex-wrap gap-2">
            <a class="button-secondary" href="{{ route('vat-rates.edit', $rate->uuid) }}">Upravit</a>
            @if ($rate->is_active)
                @unless ($rate->defaultAssignment)
                    <form method="POST" action="{{ route('vat-rates.set-default', $rate->uuid) }}">@csrf @method('PATCH')<button class="button-secondary">Nastavit výchozí</button></form>
                @endunless
                <form method="POST" action="{{ route('vat-rates.deactivate', $rate->uuid) }}">@csrf @method('PATCH')<button class="button-secondary">Deaktivovat</button></form>
            @else
                <form method="POST" action="{{ route('vat-rates.activate', $rate->uuid) }}">@csrf @method('PATCH')<button class="button-secondary">Aktivovat</button></form>
            @endif
            <form method="POST" action="{{ route('vat-rates.archive', $rate->uuid) }}" onsubmit="return confirm('Opravdu sazbu jednosměrně archivovat?')">@csrf @method('PATCH')<button class="button-secondary text-red-700">Archivovat</button></form>
        </div>
    @endunless
    @endunless
@endcan
