@php
    $isActive = (bool) old('is_active', $account->is_active);
@endphp

@if ($errors->any())
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
        <p class="font-semibold">Formulář se nepodařilo uložit.</p>
        <p class="mt-1">Opravte označená pole a odešlete jej znovu.</p>
    </div>
@endif

@if ($account->exists && $account->defaultAssignment)
    <div class="mb-5 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900" role="status">
        Tento účet je výchozí pro měnu {{ $account->defaultAssignment->currency }}.
        Jeho měnu nelze změnit, dokud nenastavíte jiný výchozí účet.
    </div>
@endif

<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <section class="card">
        <div class="mb-5">
            <h2 class="text-lg font-bold">Základní údaje</h2>
            <p class="mt-1 text-sm text-slate-600">Interní název a měna bankovního účtu.</p>
        </div>

        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <label for="name">Název účtu <span class="text-red-700" aria-hidden="true">*</span></label>
                <input
                    id="name"
                    name="name"
                    type="text"
                    value="{{ old('name', $account->name) }}"
                    maxlength="255"
                    required
                    autofocus
                    @error('name') aria-invalid="true" aria-describedby="name_error" @enderror
                >
                @error('name')
                    <p id="name_error" class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="currency">Měna <span class="text-red-700" aria-hidden="true">*</span></label>
                <select
                    id="currency"
                    name="currency"
                    required
                    @error('currency') aria-invalid="true" aria-describedby="currency_error" @enderror
                >
                    @foreach ($currencies as $code => $label)
                        <option value="{{ $code }}" @selected(old('currency', $account->currency) === $code)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('currency')
                    <p id="currency_error" class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <section class="card">
        <div class="mb-5">
            <h2 class="text-lg font-bold">Domácí účet</h2>
            <p class="mt-1 text-sm text-slate-600">
                Pro český formát vyplňte číslo účtu a čtyřmístný kód banky. Prefix je nepovinný.
            </p>
        </div>

        <div class="grid gap-5 md:grid-cols-3">
            <div>
                <label for="domestic_prefix">Prefix</label>
                <input
                    id="domestic_prefix"
                    name="domestic_prefix"
                    type="text"
                    inputmode="numeric"
                    value="{{ old('domestic_prefix', $account->domestic_prefix) }}"
                    maxlength="10"
                    @error('domestic_prefix') aria-invalid="true" aria-describedby="domestic_prefix_error" @enderror
                >
                @error('domestic_prefix')
                    <p id="domestic_prefix_error" class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="domestic_account_number">Číslo účtu</label>
                <input
                    id="domestic_account_number"
                    name="domestic_account_number"
                    type="text"
                    inputmode="numeric"
                    value="{{ old('domestic_account_number', $account->domestic_account_number) }}"
                    maxlength="32"
                    @error('domestic_account_number') aria-invalid="true" aria-describedby="domestic_account_number_error" @enderror
                >
                @error('domestic_account_number')
                    <p id="domestic_account_number_error" class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="bank_code">Kód banky</label>
                <input
                    id="bank_code"
                    name="bank_code"
                    type="text"
                    inputmode="numeric"
                    value="{{ old('bank_code', $account->bank_code) }}"
                    maxlength="4"
                    placeholder="0800"
                    @error('bank_code') aria-invalid="true" aria-describedby="bank_code_error" @enderror
                >
                @error('bank_code')
                    <p id="bank_code_error" class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <section class="card">
        <div class="mb-5">
            <h2 class="text-lg font-bold">Mezinárodní údaje</h2>
            <p class="mt-1 text-sm text-slate-600">
                Pokud nevyplníte domácí číslo účtu, je IBAN povinný. Mezery a velikost písmen se normalizují automaticky.
            </p>
        </div>

        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <label for="iban">IBAN</label>
                <input
                    id="iban"
                    name="iban"
                    type="text"
                    value="{{ old('iban', $account->iban) }}"
                    maxlength="42"
                    autocomplete="off"
                    placeholder="CZ65 0800 0000 1920 0014 5399"
                    @error('iban') aria-invalid="true" aria-describedby="iban_error" @enderror
                >
                @error('iban')
                    <p id="iban_error" class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="bic">BIC/SWIFT</label>
                <input
                    id="bic"
                    name="bic"
                    type="text"
                    value="{{ old('bic', $account->bic) }}"
                    maxlength="14"
                    autocomplete="off"
                    placeholder="GIBACZPX"
                    @error('bic') aria-invalid="true" aria-describedby="bic_error" @enderror
                >
                @error('bic')
                    <p id="bic_error" class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <section class="card">
        <div class="mb-5">
            <h2 class="text-lg font-bold">Nastavení</h2>
        </div>

        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <label for="sort_order">Pořadí <span class="text-red-700" aria-hidden="true">*</span></label>
                <input
                    id="sort_order"
                    name="sort_order"
                    type="number"
                    value="{{ old('sort_order', $account->sort_order) }}"
                    min="0"
                    max="65535"
                    required
                    @error('sort_order') aria-invalid="true" aria-describedby="sort_order_error" @enderror
                >
                @error('sort_order')
                    <p id="sort_order_error" class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-start gap-3 pt-7">
                <input
                    id="is_active"
                    name="is_active"
                    type="checkbox"
                    value="1"
                    class="mt-0.5 h-4 w-4"
                    @checked($isActive)
                >
                <div>
                    <label for="is_active">Aktivní účet</label>
                    <p class="mt-1 text-xs text-slate-500">
                        Neaktivní účet nelze nastavit jako výchozí. Deaktivace výchozí vazbu odstraní.
                    </p>
                </div>
            </div>

            <div class="md:col-span-2">
                <label for="note">Poznámka</label>
                <textarea
                    id="note"
                    name="note"
                    rows="4"
                    maxlength="5000"
                    @error('note') aria-invalid="true" aria-describedby="note_error" @enderror
                >{{ old('note', $account->note) }}</textarea>
                @error('note')
                    <p id="note_error" class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </section>

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <a href="{{ route('bank-accounts.index') }}" class="button-secondary">Zrušit</a>
        <button type="submit" class="button-primary">{{ $submitLabel }}</button>
    </div>
</form>
