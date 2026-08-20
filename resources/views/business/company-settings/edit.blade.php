<x-layouts.app title="Nastavení subjektu">
    @php
        $isVatPayer = (bool) old('is_vat_payer', $setting->is_vat_payer);
        $canUpdate = auth()->user()->can('updateAny', \App\Models\Business\CompanySetting::class);
    @endphp

    <div class="mb-6">
        <p class="text-sm font-medium text-blue-700">Nastavení</p>
        <h1 class="mt-1 text-2xl font-bold">Nastavení subjektu</h1>
        <p class="mt-2 max-w-3xl text-sm text-slate-600">
            Údaje budou použity jako výchozí údaje vystavovatele. Změny se týkají pouze právě aktivního
            fakturačního subjektu.
        </p>
    </div>

    @if ($errors->any())
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
            <p class="font-semibold">Formulář se nepodařilo uložit.</p>
            <p class="mt-1">Opravte označená pole a odešlete jej znovu.</p>
        </div>
    @endif

    @unless ($canUpdate)
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            Nastavení můžete zobrazit, ale upravovat je může pouze administrátor aktivního subjektu.
        </div>
    @endunless

    <form
        method="POST"
        action="{{ route('company-settings.update') }}"
        class="space-y-6"
        x-data="{ vatPayer: {{ $isVatPayer ? 'true' : 'false' }} }"
    >
        @csrf
        @method('PUT')

        @unless ($canUpdate)
            <fieldset disabled>
        @endunless

        <section class="card">
            <div class="mb-5">
                <h2 class="text-lg font-bold">1. Základní údaje</h2>
                <p class="mt-1 text-sm text-slate-600">Identifikační a daňové údaje vystavovatele.</p>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="legal_name">Oficiální název <span class="text-red-700" aria-hidden="true">*</span></label>
                    <input
                        id="legal_name"
                        name="legal_name"
                        type="text"
                        value="{{ old('legal_name', $setting->legal_name) }}"
                        maxlength="255"
                        required
                        autocomplete="organization"
                        @error('legal_name') aria-invalid="true" aria-describedby="legal_name_error" @enderror
                    >
                    @error('legal_name')
                        <p id="legal_name_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="additional_name">Doplňující název</label>
                    <input
                        id="additional_name"
                        name="additional_name"
                        type="text"
                        value="{{ old('additional_name', $setting->additional_name) }}"
                        maxlength="255"
                        @error('additional_name') aria-invalid="true" aria-describedby="additional_name_error" @enderror
                    >
                    @error('additional_name')
                        <p id="additional_name_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="registration_number">IČO <span class="text-red-700" aria-hidden="true">*</span></label>
                    <input
                        id="registration_number"
                        name="registration_number"
                        type="text"
                        inputmode="numeric"
                        value="{{ old('registration_number', $setting->registration_number) }}"
                        maxlength="10"
                        required
                        @error('registration_number') aria-invalid="true" aria-describedby="registration_number_error" @enderror
                    >
                    @error('registration_number')
                        <p id="registration_number_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="tax_id">DIČ</label>
                    <input
                        id="tax_id"
                        name="tax_id"
                        type="text"
                        value="{{ old('tax_id', $setting->tax_id) }}"
                        maxlength="32"
                        @error('tax_id') aria-invalid="true" aria-describedby="tax_id_error" @enderror
                    >
                    @error('tax_id')
                        <p id="tax_id_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <div class="flex items-start gap-3">
                        <input
                            id="is_vat_payer"
                            name="is_vat_payer"
                            type="checkbox"
                            value="1"
                            class="mt-0.5 h-4 w-4"
                            x-model="vatPayer"
                            @checked($isVatPayer)
                        >
                        <div>
                            <label for="is_vat_payer">Subjekt je plátcem DPH</label>
                            <p class="mt-1 text-xs text-slate-500">
                                Tato volba zatím pouze ukládá nastavení. Výpočty a sazby DPH nejsou součástí tohoto modulu.
                            </p>
                        </div>
                    </div>
                    @error('is_vat_payer')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div x-show="vatPayer">
                    <label for="vat_id">IČ DPH <span class="text-red-700" aria-hidden="true">*</span></label>
                    <input
                        id="vat_id"
                        name="vat_id"
                        type="text"
                        value="{{ old('vat_id', $setting->vat_id) }}"
                        maxlength="32"
                        :required="vatPayer"
                        @error('vat_id') aria-invalid="true" aria-describedby="vat_id_error" @enderror
                    >
                    @error('vat_id')
                        <p id="vat_id_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div x-show="vatPayer">
                    <label for="vat_registered_on">Datum registrace k DPH</label>
                    <input
                        id="vat_registered_on"
                        name="vat_registered_on"
                        type="date"
                        value="{{ old('vat_registered_on', $setting->vat_registered_on?->format('Y-m-d')) }}"
                        @error('vat_registered_on') aria-invalid="true" aria-describedby="vat_registered_on_error" @enderror
                    >
                    @error('vat_registered_on')
                        <p id="vat_registered_on_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        <section class="card">
            <div class="mb-5">
                <h2 class="text-lg font-bold">2. Adresa</h2>
                <p class="mt-1 text-sm text-slate-600">Sídlo nebo místo podnikání uváděné na dokladech.</p>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="street">Ulice <span class="text-red-700" aria-hidden="true">*</span></label>
                    <input
                        id="street"
                        name="street"
                        type="text"
                        value="{{ old('street', $setting->street) }}"
                        maxlength="255"
                        required
                        autocomplete="street-address"
                        @error('street') aria-invalid="true" aria-describedby="street_error" @enderror
                    >
                    @error('street')
                        <p id="street_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="house_number">Číslo popisné</label>
                    <input
                        id="house_number"
                        name="house_number"
                        type="text"
                        value="{{ old('house_number', $setting->house_number) }}"
                        maxlength="32"
                        @error('house_number') aria-invalid="true" aria-describedby="house_number_error" @enderror
                    >
                    @error('house_number')
                        <p id="house_number_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="orientation_number">Číslo orientační</label>
                    <input
                        id="orientation_number"
                        name="orientation_number"
                        type="text"
                        value="{{ old('orientation_number', $setting->orientation_number) }}"
                        maxlength="32"
                        @error('orientation_number') aria-invalid="true" aria-describedby="orientation_number_error" @enderror
                    >
                    @error('orientation_number')
                        <p id="orientation_number_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="city">Město <span class="text-red-700" aria-hidden="true">*</span></label>
                    <input
                        id="city"
                        name="city"
                        type="text"
                        value="{{ old('city', $setting->city) }}"
                        maxlength="128"
                        required
                        autocomplete="address-level2"
                        @error('city') aria-invalid="true" aria-describedby="city_error" @enderror
                    >
                    @error('city')
                        <p id="city_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="postal_code">PSČ <span class="text-red-700" aria-hidden="true">*</span></label>
                    <input
                        id="postal_code"
                        name="postal_code"
                        type="text"
                        value="{{ old('postal_code', $setting->postal_code) }}"
                        maxlength="20"
                        required
                        autocomplete="postal-code"
                        @error('postal_code') aria-invalid="true" aria-describedby="postal_code_error" @enderror
                    >
                    @error('postal_code')
                        <p id="postal_code_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="country_code">Stát <span class="text-red-700" aria-hidden="true">*</span></label>
                    <select
                        id="country_code"
                        name="country_code"
                        required
                        autocomplete="country"
                        @error('country_code') aria-invalid="true" aria-describedby="country_code_error" @enderror
                    >
                        @foreach ($countries as $code => $label)
                            <option value="{{ $code }}" @selected(old('country_code', $setting->country_code) === $code)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('country_code')
                        <p id="country_code_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        <section class="card">
            <div class="mb-5">
                <h2 class="text-lg font-bold">3. Kontakty</h2>
                <p class="mt-1 text-sm text-slate-600">Kontaktní údaje zobrazované na dokladech.</p>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="email">E-mail <span class="text-red-700" aria-hidden="true">*</span></label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email', $setting->email) }}"
                        maxlength="255"
                        required
                        autocomplete="email"
                        @error('email') aria-invalid="true" aria-describedby="email_error" @enderror
                    >
                    @error('email')
                        <p id="email_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="phone">Telefon</label>
                    <input
                        id="phone"
                        name="phone"
                        type="tel"
                        value="{{ old('phone', $setting->phone) }}"
                        maxlength="64"
                        autocomplete="tel"
                        @error('phone') aria-invalid="true" aria-describedby="phone_error" @enderror
                    >
                    @error('phone')
                        <p id="phone_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="website">Web</label>
                    <input
                        id="website"
                        name="website"
                        type="url"
                        value="{{ old('website', $setting->website) }}"
                        maxlength="255"
                        placeholder="https://example.cz"
                        autocomplete="url"
                        @error('website') aria-invalid="true" aria-describedby="website_error" @enderror
                    >
                    @error('website')
                        <p id="website_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        <section class="card">
            <div class="mb-5">
                <h2 class="text-lg font-bold">4. Výchozí nastavení dokladů</h2>
                <p class="mt-1 text-sm text-slate-600">Hodnoty předvyplněné při budoucím vytváření dokladů.</p>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="default_currency">Měna <span class="text-red-700" aria-hidden="true">*</span></label>
                    <select id="default_currency" name="default_currency" required>
                        @foreach ($currencies as $code => $label)
                            <option value="{{ $code }}" @selected(old('default_currency', $setting->default_currency) === $code)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('default_currency')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="document_locale">Jazyk dokladů <span class="text-red-700" aria-hidden="true">*</span></label>
                    <select id="document_locale" name="document_locale" required>
                        @foreach ($documentLocales as $code => $label)
                            <option value="{{ $code }}" @selected(old('document_locale', $setting->document_locale) === $code)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('document_locale')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="timezone">Časové pásmo <span class="text-red-700" aria-hidden="true">*</span></label>
                    <select id="timezone" name="timezone" required>
                        @foreach ($timezones as $value => $label)
                            <option value="{{ $value }}" @selected(old('timezone', $setting->timezone) === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('timezone')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="default_due_days">Výchozí splatnost ve dnech <span class="text-red-700" aria-hidden="true">*</span></label>
                    <input
                        id="default_due_days"
                        name="default_due_days"
                        type="number"
                        value="{{ old('default_due_days', $setting->default_due_days) }}"
                        min="0"
                        max="365"
                        required
                        @error('default_due_days') aria-invalid="true" aria-describedby="default_due_days_error" @enderror
                    >
                    @error('default_due_days')
                        <p id="default_due_days_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="default_payment_method">Výchozí způsob úhrady <span class="text-red-700" aria-hidden="true">*</span></label>
                    <select id="default_payment_method" name="default_payment_method" required>
                        @foreach ($paymentMethods as $code => $label)
                            <option value="{{ $code }}" @selected(old('default_payment_method', $setting->default_payment_method) === $code)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('default_payment_method')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="invoice_intro">Text před položkami</label>
                    <textarea
                        id="invoice_intro"
                        name="invoice_intro"
                        rows="4"
                        maxlength="5000"
                        @error('invoice_intro') aria-invalid="true" aria-describedby="invoice_intro_error" @enderror
                    >{{ old('invoice_intro', $setting->invoice_intro) }}</textarea>
                    @error('invoice_intro')
                        <p id="invoice_intro_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="invoice_outro">Text ve spodní části faktury</label>
                    <textarea
                        id="invoice_outro"
                        name="invoice_outro"
                        rows="4"
                        maxlength="5000"
                        @error('invoice_outro') aria-invalid="true" aria-describedby="invoice_outro_error" @enderror
                    >{{ old('invoice_outro', $setting->invoice_outro) }}</textarea>
                    @error('invoice_outro')
                        <p id="invoice_outro_error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        @unless ($canUpdate)
            </fieldset>
        @endunless

        @if ($canUpdate)
            <div class="flex justify-end">
                <button type="submit" class="button-primary">Uložit nastavení subjektu</button>
            </div>
        @endif
    </form>
</x-layouts.app>
