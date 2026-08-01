@include('business.vat-rates._warning')

<div class="mt-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" x-data="{ taxType: @js(old('tax_type', $rate->tax_type?->value ?? 'standard')) }">
    <div class="grid gap-5 md:grid-cols-2">
        <label class="block">
            <span class="form-label">Název</span>
            <input class="form-input" name="name" maxlength="255" required value="{{ old('name', $rate->name) }}">
            @error('name') <span class="form-error">{{ $message }}</span> @enderror
        </label>
        <label class="block">
            <span class="form-label">Kód</span>
            <input class="form-input uppercase" name="code" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9_-]*" required value="{{ old('code', $rate->code) }}">
            @error('code') <span class="form-error">{{ $message }}</span> @enderror
        </label>
        <label class="block">
            <span class="form-label">Daňový režim</span>
            <select class="form-input" name="tax_type" required x-model="taxType">
                @foreach ($taxTypes as $value => $label)
                    <option value="{{ $value }}" @selected(old('tax_type', $rate->tax_type?->value) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('tax_type') <span class="form-error">{{ $message }}</span> @enderror
        </label>
        <label class="block" x-show="['standard', 'reduced', 'zero'].includes(taxType)">
            <span class="form-label">Sazba v %</span>
            <input class="form-input" name="percentage" inputmode="decimal" value="{{ old('percentage', $rate->percentage) }}" placeholder="21,0000">
            <span class="mt-1 block text-xs text-slate-500">Zadejte procentní údaj, například 21 nebo 12,5.</span>
            @error('percentage') <span class="form-error">{{ $message }}</span> @enderror
        </label>
        <label class="block">
            <span class="form-label">Platnost od</span>
            <input class="form-input" type="date" name="valid_from" required value="{{ old('valid_from', $rate->valid_from?->format('Y-m-d')) }}">
            @error('valid_from') <span class="form-error">{{ $message }}</span> @enderror
        </label>
        <label class="block">
            <span class="form-label">Platnost do</span>
            <input class="form-input" type="date" name="valid_to" value="{{ old('valid_to', $rate->valid_to?->format('Y-m-d')) }}">
            @error('valid_to') <span class="form-error">{{ $message }}</span> @enderror
        </label>
        <label class="block">
            <span class="form-label">Pořadí</span>
            <input class="form-input" type="number" min="0" max="65535" name="sort_order" required value="{{ old('sort_order', $rate->sort_order ?? 0) }}">
            @error('sort_order') <span class="form-error">{{ $message }}</span> @enderror
        </label>
        <label class="flex items-center gap-3 self-end rounded-lg border border-slate-200 p-3">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rate->is_active ?? true))>
            <span>Aktivní sazba</span>
        </label>
    </div>

    <div class="mt-5 rounded-lg bg-blue-50 p-4 text-sm text-blue-900">
        Platnost od i do je včetně uvedeného dne. Historickou sazbu nepřepisujte; pro nové období vytvořte nový záznam.
    </div>
</div>
