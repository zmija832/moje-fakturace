<x-layouts.app :title="$client->display_name">
    @php $canManage = auth()->user()->can('updateAny', \App\Models\Business\Client::class); @endphp
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div><p class="text-sm font-medium text-blue-700">Klient</p><h1 class="mt-1 text-2xl font-bold">{{ $client->display_name }}</h1><p class="mt-2 text-sm text-slate-600">{{ $client->type->label() }}</p></div>
        @if($canManage && !$client->isArchived())<a href="{{ route('clients.edit', $client->uuid) }}" class="button-primary">Upravit klienta</a>@endif
    </div>

    @if($client->isArchived())<div class="mb-6 rounded-xl border border-slate-300 bg-slate-100 p-4 font-semibold">Archivovaný klient – údaje jsou pouze ke čtení.</div>@endif

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="card"><h2 class="text-lg font-bold">Základní a identifikační údaje</h2><dl class="mt-4 space-y-3 text-sm">
            <div><dt class="text-slate-500">Název</dt><dd class="font-semibold">{{ $client->display_name }}</dd></div>
            @if($client->company_name)<div><dt class="text-slate-500">Firma</dt><dd>{{ $client->company_name }}</dd></div>@endif
            @if($client->first_name || $client->last_name)<div><dt class="text-slate-500">Jméno</dt><dd>{{ $client->first_name }} {{ $client->last_name }}</dd></div>@endif
            @foreach(['registration_number' => 'IČO','tax_id' => 'DIČ','vat_id' => 'IČ DPH','contact_person' => 'Kontaktní osoba'] as $field => $label)@if($client->{$field})<div><dt class="text-slate-500">{{ $label }}</dt><dd>{{ $client->{$field} }}</dd></div>@endif @endforeach
        </dl></section>
        <section class="card"><h2 class="text-lg font-bold">Adresy</h2><dl class="mt-4 space-y-4 text-sm"><div><dt class="text-slate-500">Fakturační</dt><dd>{{ $client->formattedBillingAddress() }}</dd></div><div><dt class="text-slate-500">Dodací</dt><dd>{{ $client->formattedDeliveryAddress() ?? 'Shodná s fakturační adresou' }}</dd></div></dl></section>
        <section class="card"><h2 class="text-lg font-bold">Kontakty</h2><dl class="mt-4 space-y-3 text-sm">@foreach(['email'=>'E-mail','phone'=>'Telefon','website'=>'Web'] as $field=>$label)<div><dt class="text-slate-500">{{ $label }}</dt><dd class="break-all">{{ $client->{$field} ?? '—' }}</dd></div>@endforeach</dl></section>
        <section class="card"><h2 class="text-lg font-bold">Výchozí fakturace a stav</h2><dl class="mt-4 space-y-3 text-sm"><div><dt class="text-slate-500">Měna</dt><dd>{{ $client->default_currency ?? 'Podle subjektu' }}</dd></div><div><dt class="text-slate-500">Splatnost</dt><dd>{{ $client->default_due_days !== null ? $client->default_due_days.' dní' : 'Podle subjektu' }}</dd></div><div><dt class="text-slate-500">Způsob úhrady</dt><dd>{{ \App\Enums\DefaultPaymentMethod::tryFrom((string)$client->default_payment_method)?->label() ?? 'Podle subjektu' }}</dd></div><div><dt class="text-slate-500">Jazyk</dt><dd>{{ $client->language ?? 'Podle subjektu' }}</dd></div><div><dt class="text-slate-500">Stav</dt><dd>{{ $client->isArchived() ? 'Archivovaný' : ($client->is_active ? 'Aktivní' : 'Neaktivní') }}</dd></div></dl></section>
    </div>
    @if($client->note)<section class="card mt-6"><h2 class="text-lg font-bold">Poznámka</h2><p class="mt-3 whitespace-pre-line text-sm">{{ $client->note }}</p></section>@endif
    <div class="mt-6"><a href="{{ route('clients.index') }}" class="button-secondary">Zpět na klienty</a></div>
</x-layouts.app>
