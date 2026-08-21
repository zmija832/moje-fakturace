@if($errors->any())<div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">Opravte označená pole.</div>@endif
<form method="POST" action="{{ $action }}" class="card space-y-5">@csrf @if($method !== 'POST') @method($method) @endif
    <div><label for="name">Název / popis *</label><textarea id="name" name="name" rows="3" maxlength="255" required>{{ old('name', $item->name) }}</textarea>@error('name')<p class="field-error">{{ $message }}</p>@enderror</div>
    <div class="grid gap-5 sm:grid-cols-3">
        <div><label for="unit_price">Cena za MJ</label><input id="unit_price" name="unit_price" inputmode="decimal" value="{{ old('unit_price', $item->unit_price === null ? '' : \App\Domain\Invoices\InvoiceDecimal::formatInput($item->unit_price)) }}"><p class="mt-1 text-xs text-slate-500">Nevyplněnou cenu doplníte až na faktuře.</p>@error('unit_price')<p class="field-error">{{ $message }}</p>@enderror</div>
        <div><label for="unit">MJ *</label><input id="unit" name="unit" maxlength="32" required value="{{ old('unit', $item->unit) }}">@error('unit')<p class="field-error">{{ $message }}</p>@enderror</div>
        <div><label for="currency">Měna *</label><select id="currency" name="currency" required>@foreach($currencies as $code=>$label)<option value="{{ $code }}" @selected(old('currency',$item->currency)===$code)>{{ $label }}</option>@endforeach</select>@error('currency')<p class="field-error">{{ $message }}</p>@enderror</div>
    </div>
    @if($isVatPayer)<div><label for="vat_rate_uuid">Výchozí sazba DPH</label><select id="vat_rate_uuid" name="vat_rate_uuid"><option value="">Použít sazbu z faktury</option>@foreach($vatRates as $rate)<option value="{{ $rate->uuid }}" @selected(old('vat_rate_uuid',$item->vat_rate_uuid)===$rate->uuid)>{{ $rate->name }}</option>@endforeach</select>@error('vat_rate_uuid')<p class="field-error">{{ $message }}</p>@enderror</div>@endif
    <label class="flex items-center gap-3"><input class="h-4 w-4" type="checkbox" name="is_active" value="1" @checked(old('is_active',$item->is_active))> Aktivní</label>
    <div class="flex justify-end gap-3"><a class="button-secondary" href="{{ route('invoice-catalog.index') }}">Zrušit</a><button class="button-primary">Uložit</button></div>
</form>
