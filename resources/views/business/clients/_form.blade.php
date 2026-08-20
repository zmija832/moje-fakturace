@php
    $selectedType = old('type', $client->type?->value ?? \App\Enums\ClientType::Company->value);
    $isActive = (bool) old('is_active', $client->is_active);
@endphp

@if ($errors->any())
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
        <p class="font-semibold">Formulář se nepodařilo uložit.</p>
        <p class="mt-1">Opravte označená pole a odešlete jej znovu.</p>
    </div>
@endif

<form method="POST" action="{{ $action }}" class="space-y-6" x-data="aresClientForm({url:@js(route('clients.ares.lookup')),csrf:@js(csrf_token())})" x-ref="form">
    @csrf
    @if ($method !== 'POST') @method($method) @endif

    <section class="card">
        <h2 class="mb-5 text-lg font-bold">1. Typ a základní údaje</h2>
        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <label for="type">Typ klienta *</label>
                <select id="type" name="type" required @error('type') aria-invalid="true" @enderror>
                    @foreach($types as $value => $label)<option value="{{ $value }}" @selected($selectedType === $value)>{{ $label }}</option>@endforeach
                </select>
                @error('type')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="display_name">Zobrazovaný název</label>
                <input id="display_name" name="display_name" maxlength="255" value="{{ old('display_name', $client->display_name) }}" @error('display_name') aria-invalid="true" @enderror>
                <p class="mt-1 text-xs text-slate-500">Prázdný název se při uložení doplní z firmy nebo jména.</p>
                @error('display_name')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            @foreach(['company_name' => 'Název firmy', 'first_name' => 'Jméno', 'last_name' => 'Příjmení'] as $field => $label)
                <div><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" maxlength="{{ $field === 'company_name' ? 255 : 128 }}" value="{{ old($field, $client->{$field}) }}" @error($field) aria-invalid="true" @enderror>@error($field)<p class="field-error">{{ $message }}</p>@enderror</div>
            @endforeach
        </div>
        <p class="mt-4 text-xs text-slate-500">U firmy jsou jméno a příjmení ignorovány. U fyzické osoby se neukládají firemní identifikátory.</p>
    </section>

    <section class="card">
        <h2 class="mb-5 text-lg font-bold">2. Identifikační údaje</h2>
        <div x-cloak x-show="message" x-text="message" class="mb-4 rounded-xl border border-green-200 bg-green-50 p-3 text-sm text-green-800"></div>
        <div x-cloak x-show="warning" x-text="warning" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"></div>
        <div x-cloak x-show="error" x-text="error" class="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800"></div>
        <div class="grid gap-5 md:grid-cols-2">
            @foreach(['registration_number' => 'IČO', 'tax_id' => 'DIČ', 'vat_id' => 'IČ DPH', 'contact_person' => 'Kontaktní osoba'] as $field => $label)
                <div><label for="{{ $field }}">{{ $label }}</label><div class="flex gap-2"><input id="{{ $field }}" name="{{ $field }}" maxlength="{{ $field === 'contact_person' ? 255 : 32 }}" value="{{ old($field, $client->{$field}) }}" @error($field) aria-invalid="true" @enderror>@if($field==='registration_number')<button type="button" class="button-secondary shrink-0" :disabled="loading" @click="lookup" x-text="loading?'Načítám…':'Načíst z ARES'"></button>@endif</div>@error($field)<p class="field-error">{{ $message }}</p>@enderror</div>
            @endforeach
        </div>
    </section>

    <section class="card">
        <h2 class="mb-5 text-lg font-bold">3. Fakturační adresa</h2>
        <div class="grid gap-5 md:grid-cols-2">
            @foreach(['street' => ['Ulice', 255], 'house_number' => ['Číslo popisné', 32], 'orientation_number' => ['Číslo orientační', 32], 'city' => ['Město', 128], 'postal_code' => ['PSČ', 16]] as $field => [$label, $max])
                <div><label for="{{ $field }}">{{ $label }}{{ in_array($field, ['street','city','postal_code'], true) ? ' *' : '' }}</label><input id="{{ $field }}" name="{{ $field }}" maxlength="{{ $max }}" value="{{ old($field, $client->{$field}) }}" @if(in_array($field, ['street','city','postal_code'], true)) required @endif @error($field) aria-invalid="true" @enderror>@error($field)<p class="field-error">{{ $message }}</p>@enderror</div>
            @endforeach
            <div><label for="country_code">Stát *</label><select id="country_code" name="country_code" required>@foreach($countries as $value => $label)<option value="{{ $value }}" @selected(old('country_code', $client->country_code) === $value)>{{ $label }}</option>@endforeach</select>@error('country_code')<p class="field-error">{{ $message }}</p>@enderror</div>
        </div>
    </section>

    <section class="card">
        <h2 class="text-lg font-bold">4. Dodací adresa</h2>
        <p class="mb-5 mt-1 text-sm text-slate-600">Volitelné. Pokud ji začnete vyplňovat, ulice, město, PSČ a stát jsou povinné.</p>
        <div class="grid gap-5 md:grid-cols-2">
            @foreach(['delivery_name' => ['Název příjemce',255], 'delivery_street' => ['Ulice',255], 'delivery_house_number' => ['Číslo popisné',32], 'delivery_orientation_number' => ['Číslo orientační',32], 'delivery_city' => ['Město',128], 'delivery_postal_code' => ['PSČ',16]] as $field => [$label,$max])
                <div><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" maxlength="{{ $max }}" value="{{ old($field, $client->{$field}) }}" @error($field) aria-invalid="true" @enderror>@error($field)<p class="field-error">{{ $message }}</p>@enderror</div>
            @endforeach
            <div><label for="delivery_country_code">Stát</label><select id="delivery_country_code" name="delivery_country_code"><option value="">Nevyplněno</option>@foreach($countries as $value => $label)<option value="{{ $value }}" @selected(old('delivery_country_code', $client->delivery_country_code) === $value)>{{ $label }}</option>@endforeach</select>@error('delivery_country_code')<p class="field-error">{{ $message }}</p>@enderror</div>
        </div>
    </section>

    <section class="card">
        <h2 class="mb-5 text-lg font-bold">5. Kontakty</h2>
        <div class="grid gap-5 md:grid-cols-2">
            @foreach(['email' => ['E-mail','email',255], 'phone' => ['Telefon','text',64], 'website' => ['Web','url',255]] as $field => [$label,$type,$max])
                <div><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" maxlength="{{ $max }}" value="{{ old($field, $client->{$field}) }}" @error($field) aria-invalid="true" @enderror>@error($field)<p class="field-error">{{ $message }}</p>@enderror</div>
            @endforeach
        </div>
    </section>

    <section class="card">
        <h2 class="mb-5 text-lg font-bold">6. Výchozí nastavení fakturace</h2>
        <div class="grid gap-5 md:grid-cols-2">
            <div><label for="default_currency">Měna</label><select id="default_currency" name="default_currency"><option value="">Podle subjektu</option>@foreach($currencies as $value => $label)<option value="{{ $value }}" @selected(old('default_currency', $client->default_currency) === $value)>{{ $label }}</option>@endforeach</select>@error('default_currency')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><label for="default_due_days">Splatnost ve dnech</label><input id="default_due_days" name="default_due_days" type="number" min="0" max="365" value="{{ old('default_due_days', $client->default_due_days) }}">@error('default_due_days')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><label for="default_payment_method">Způsob úhrady</label><select id="default_payment_method" name="default_payment_method"><option value="">Podle subjektu</option>@foreach($paymentMethods as $value => $label)<option value="{{ $value }}" @selected(old('default_payment_method', $client->default_payment_method) === $value)>{{ $label }}</option>@endforeach</select>@error('default_payment_method')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><label for="language">Jazyk</label><select id="language" name="language"><option value="">Podle subjektu</option>@foreach($languages as $value => $label)<option value="{{ $value }}" @selected(old('language', $client->language) === $value)>{{ $label }}</option>@endforeach</select>@error('language')<p class="field-error">{{ $message }}</p>@enderror</div>
        </div>
    </section>

    <section class="card">
        <h2 class="mb-5 text-lg font-bold">7. Poznámka a stav</h2>
        <label for="note">Poznámka</label><textarea id="note" name="note" rows="5" maxlength="5000">{{ old('note', $client->note) }}</textarea>@error('note')<p class="field-error">{{ $message }}</p>@enderror
        <div class="mt-5 flex items-start gap-3"><input id="is_active" name="is_active" type="checkbox" value="1" class="mt-1 h-4 w-4" @checked($isActive)><div><label for="is_active">Aktivní klient</label><p class="text-xs text-slate-500">Neaktivní klient zůstává uložený a lze jej znovu aktivovat.</p></div></div>
    </section>

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><a href="{{ route('clients.index') }}" class="button-secondary">Zrušit</a><button class="button-primary" type="submit">{{ $submitLabel }}</button></div>
</form>
