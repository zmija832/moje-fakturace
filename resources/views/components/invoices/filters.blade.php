@props(['filters', 'clients'])
@php
    $activeFilterCount = collect([
        filled($filters['search'] ?? null),
        isset($filters['visibility']) && $filters['visibility'] !== 'active',
        isset($filters['status']) && $filters['status'] !== 'all',
        filled($filters['client_uuid'] ?? null),
        isset($filters['currency']) && $filters['currency'] !== 'all',
        isset($filters['payment_status']) && $filters['payment_status'] !== 'all',
        filled($filters['issued_from'] ?? null),
        filled($filters['issued_to'] ?? null),
        filled($filters['due_from'] ?? null),
        filled($filters['due_to'] ?? null),
        filter_var($filters['overdue'] ?? false, FILTER_VALIDATE_BOOL),
    ])->filter()->count();
@endphp
<div class="mb-6" x-data="{ filtersOpen: {{ $activeFilterCount > 0 ? 'true' : 'false' }} }" data-filter-count="{{ $activeFilterCount }}">
    <button type="button" class="button-secondary" aria-controls="invoice-filter-panel" :aria-expanded="filtersOpen" @click="filtersOpen = ! filtersOpen">
        <span>Filtry<span @if($activeFilterCount === 0) class="hidden" @endif> ({{ $activeFilterCount }})</span></span>
        <span class="ml-2" aria-hidden="true" x-text="filtersOpen ? '▴' : '▾'"></span>
    </button>
<form x-cloak x-show="filtersOpen" id="invoice-filter-panel" method="GET" action="{{ route('invoices.index') }}" class="card mt-3">
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="xl:col-span-2"><label for="search">Hledat</label><input id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" placeholder="Číslo, klient, IČO, variabilní symbol nebo UUID"></div>
        <div><label for="visibility">Zobrazení</label><select id="visibility" name="visibility">@foreach(['active'=>'Aktivní','drafts'=>'Koncepty','unpaid'=>'Neuhrazené','paid'=>'Uhrazené','cancelled'=>'Stornované','archived'=>'Archivované','all'=>'Vše'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['visibility'] ?? 'active') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="status">Typ stavu</label><select id="status" name="status"><option value="all">Všechny</option>@foreach(\App\Enums\InvoiceStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
        <div><label for="client_uuid">Klient</label><select id="client_uuid" name="client_uuid"><option value="">Všichni klienti</option>@foreach($clients as $client)<option value="{{ $client->uuid }}" @selected(($filters['client_uuid'] ?? '') === $client->uuid)>{{ $client->display_name }}</option>@endforeach</select></div>
        <div><label for="currency">Měna</label><select id="currency" name="currency"><option value="all">Všechny</option>@foreach(['CZK','EUR'] as $currency)<option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ \App\Domain\Invoices\InvoiceDecimal::currencyLabel($currency) }}</option>@endforeach</select></div>
        <div><label for="payment_status">Stav úhrady</label><select id="payment_status" name="payment_status"><option value="all">Všechny</option>@foreach(\App\Enums\InvoicePaymentStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['payment_status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
        @foreach(['issued_from'=>'Vystavení od','issued_to'=>'Vystavení do','due_from'=>'Splatnost od','due_to'=>'Splatnost do'] as $field=>$label)
            <div><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" type="date" value="{{ $filters[$field] ?? '' }}"></div>
        @endforeach
        <div><label for="sort">Řazení</label><select id="sort" name="sort">@foreach(['created_at'=>'Vytvoření','issued_on'=>'Datum vystavení','due_on'=>'Splatnost','document_number'=>'Číslo dokladu'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['sort'] ?? 'created_at') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="direction">Směr</label><select id="direction" name="direction"><option value="desc">Od nejnovějších</option><option value="asc" @selected(($filters['direction'] ?? '') === 'asc')>Od nejstarších</option></select></div>
        <div class="flex items-center gap-2 pt-6"><input id="overdue" name="overdue" type="checkbox" value="1" @checked($filters['overdue'] ?? false)><label for="overdue">Po splatnosti s nedoplatkem</label></div>
    </div>
    <div class="mt-5 flex flex-wrap gap-2"><button class="button-primary" type="submit">Filtrovat</button><a class="button-secondary" href="{{ route('invoices.index') }}">Zrušit filtry</a></div>
</form>
</div>
