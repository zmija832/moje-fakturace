@props(['clientTypes', 'countries'])

<div
    x-cloak
    x-show="quickClientOpen"
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="quick-client-title"
    @keydown.escape.window="closeQuickClient"
    @click.self="closeQuickClient"
>
    <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
        <div class="flex items-start justify-between gap-4">
            <div><h2 id="quick-client-title" class="text-xl font-bold">Nový klient</h2><p class="mt-1 text-sm text-slate-600">Klienta po vytvoření automaticky vybereme do faktury.</p></div>
            <button type="button" class="button-secondary px-3" aria-label="Zavřít" @click="closeQuickClient">×</button>
        </div>

        <div x-show="quickClientGeneralError" x-text="quickClientGeneralError" class="mt-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert"></div>
        <div x-show="aresMessage" x-text="aresMessage" class="mt-5 rounded-xl border border-green-200 bg-green-50 p-3 text-sm text-green-800" role="status"></div>
        <div x-show="aresWarning" x-text="aresWarning" class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="status"></div>

        <form x-ref="quickClientForm" class="mt-6 space-y-5" @submit.prevent="createQuickClient" novalidate>
            <div class="grid gap-5 md:grid-cols-2">
                <div><label for="quick-client-type">Typ klienta *</label><select id="quick-client-type" name="type" x-model="quickClientType" required>@foreach($clientTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select><p x-show="quickClientFieldError('type')" x-text="quickClientFieldError('type')" class="field-error"></p></div>
                <div x-show="quickClientType === 'company'"><label for="quick-client-company-name">Firma *</label><input x-ref="quickClientFirst" id="quick-client-company-name" name="company_name" maxlength="255" :required="quickClientType === 'company'"><p x-show="quickClientFieldError('company_name')" x-text="quickClientFieldError('company_name')" class="field-error"></p></div>
                <div x-show="quickClientType === 'person'"><label for="quick-client-first-name">Jméno *</label><input id="quick-client-first-name" name="first_name" maxlength="128" :required="quickClientType === 'person'"><p x-show="quickClientFieldError('first_name')" x-text="quickClientFieldError('first_name')" class="field-error"></p></div>
                <div x-show="quickClientType === 'person'"><label for="quick-client-last-name">Příjmení *</label><input id="quick-client-last-name" name="last_name" maxlength="128" :required="quickClientType === 'person'"><p x-show="quickClientFieldError('last_name')" x-text="quickClientFieldError('last_name')" class="field-error"></p></div>
                <div x-show="quickClientType === 'company'">
                    <label for="quick-client-registration-number">IČO</label>
                    <div class="flex items-stretch gap-2">
                        <input id="quick-client-registration-number" name="registration_number" inputmode="numeric" maxlength="32">
                        <button type="button" class="button-secondary shrink-0 whitespace-nowrap" :disabled="aresLoading || quickClientSubmitting" @click="loadQuickClientFromAres" x-text="aresLoading ? 'Načítám…' : 'Načíst z ARES'"></button>
                    </div>
                    <p x-show="quickClientFieldError('registration_number')" x-text="quickClientFieldError('registration_number')" class="field-error"></p>
                </div>
                <div x-show="quickClientType === 'company'"><label for="quick-client-tax-id">DIČ</label><input id="quick-client-tax-id" name="tax_id" maxlength="32"><p x-show="quickClientFieldError('tax_id')" x-text="quickClientFieldError('tax_id')" class="field-error"></p></div>
                <div><label for="quick-client-phone">Telefon</label><input id="quick-client-phone" name="phone" maxlength="64"><p x-show="quickClientFieldError('phone')" x-text="quickClientFieldError('phone')" class="field-error"></p></div>
                <div><label for="quick-client-email">E-mail</label><input id="quick-client-email" name="email" type="email" maxlength="255"><p x-show="quickClientFieldError('email')" x-text="quickClientFieldError('email')" class="field-error"></p></div>
                <div class="md:col-span-2"><label for="quick-client-street">Ulice *</label><input id="quick-client-street" name="street" maxlength="255" required><p x-show="quickClientFieldError('street')" x-text="quickClientFieldError('street')" class="field-error"></p></div>
                <div><label for="quick-client-city">Město *</label><input id="quick-client-city" name="city" maxlength="128" required><p x-show="quickClientFieldError('city')" x-text="quickClientFieldError('city')" class="field-error"></p></div>
                <div><label for="quick-client-postal-code">PSČ *</label><input id="quick-client-postal-code" name="postal_code" maxlength="16" required><p x-show="quickClientFieldError('postal_code')" x-text="quickClientFieldError('postal_code')" class="field-error"></p></div>
                <div><label for="quick-client-country-code">Stát *</label><select id="quick-client-country-code" name="country_code" required>@foreach($countries as $value => $label)<option value="{{ $value }}" @selected($value === 'CZ')>{{ $label }}</option>@endforeach</select><p x-show="quickClientFieldError('country_code')" x-text="quickClientFieldError('country_code')" class="field-error"></p></div>
            </div>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><button type="button" class="button-secondary" @click="closeQuickClient">Zrušit</button><button type="submit" class="button-primary" :disabled="quickClientSubmitting || aresLoading" x-text="quickClientSubmitting ? 'Ukládám…' : 'Vytvořit a vybrat'"></button></div>
        </form>
    </div>
</div>
