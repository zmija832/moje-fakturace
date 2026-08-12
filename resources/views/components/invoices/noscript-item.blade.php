@props(['vatRates','discountTypes','isVatPayer'=>false])
<noscript><fieldset class="mt-5 rounded-xl border border-slate-200 p-4"><legend class="px-2 font-semibold">Položka 1</legend><input type="hidden" name="items[0][position]" value="1"><div class="grid gap-4 md:grid-cols-2">
    <div><label for="ns-description">Popis *</label><input id="ns-description" name="items[0][description]" value="{{ old('items.0.description') }}" required></div>
    <div><label for="ns-quantity">Množství *</label><input id="ns-quantity" name="items[0][quantity]" value="{{ old('items.0.quantity','1') }}" required></div>
    <div><label for="ns-unit">Jednotka</label><input id="ns-unit" name="items[0][unit]" value="{{ old('items.0.unit','ks') }}"></div>
    <div><label for="ns-price">Cena bez DPH *</label><input id="ns-price" name="items[0][unit_price]" value="{{ old('items.0.unit_price','0') }}" required></div>
    <div><label for="ns-discount">Sleva</label><select id="ns-discount" name="items[0][discount_type]">@foreach($discountTypes as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
    <div><label for="ns-discount-value">Hodnota slevy</label><input id="ns-discount-value" name="items[0][discount_value]" value="{{ old('items.0.discount_value','0') }}"></div>
    @if($isVatPayer)<div><label for="ns-vat">Sazba DPH *</label><select id="ns-vat" name="items[0][vat_rate_uuid]" required>@foreach($vatRates as $rate)<option value="{{ $rate->uuid }}" @selected(old('items.0.vat_rate_uuid')===$rate->uuid)>{{ $rate->name }}</option>@endforeach</select></div>@endif
</div><p class="mt-3 text-sm text-slate-600">Bez JavaScriptu lze vytvořit jednu položku. Další položky lze doplnit po zapnutí JavaScriptu.</p></fieldset></noscript>
