@props(['vatRates', 'discountTypes', 'isVatPayer' => false])

<noscript>
    <fieldset class="mt-5 border-t border-slate-200 pt-4">
        <legend class="sr-only">Položka faktury</legend>
        <input type="hidden" name="items[0][position]" value="1">
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-12">
            <div class="xl:col-span-1"><label for="ns-quantity">Množství *</label><input id="ns-quantity" name="items[0][quantity]" value="{{ old('items.0.quantity', '1') }}" required></div>
            <div class="xl:col-span-1"><label for="ns-unit">MJ</label><input id="ns-unit" name="items[0][unit]" value="{{ old('items.0.unit', 'ks') }}"></div>
            <div class="col-span-2 xl:col-span-{{ $isVatPayer ? 3 : 4 }}"><label for="ns-description">Popis *</label><input id="ns-description" name="items[0][description]" value="{{ old('items.0.description') }}" required></div>
            <div class="xl:col-span-2"><label for="ns-price">Cena za MJ *</label><input id="ns-price" name="items[0][unit_price]" value="{{ old('items.0.unit_price', '0') }}" required></div>
            <div class="col-span-2 xl:col-span-3">
                <label for="ns-discount">Sleva</label>
                <div class="flex gap-2"><select id="ns-discount" name="items[0][discount_type]">@foreach($discountTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select><input aria-label="Hodnota slevy" name="items[0][discount_value]" value="{{ old('items.0.discount_value', '0') }}"></div>
            </div>
            @if($isVatPayer)
                <div class="col-span-2 xl:col-span-2"><label for="ns-vat">DPH *</label><select id="ns-vat" name="items[0][vat_rate_uuid]" required>@foreach($vatRates as $rate)<option value="{{ $rate->uuid }}" @selected(old('items.0.vat_rate_uuid') === $rate->uuid)>{{ $rate->name }}</option>@endforeach</select></div>
            @endif
        </div>
        <p class="mt-3 text-sm text-slate-600">Bez JavaScriptu lze vytvořit jednu položku. Další položky lze doplnit po zapnutí JavaScriptu.</p>
    </fieldset>
</noscript>
