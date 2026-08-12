@props(['vatRates', 'discountTypes', 'isVatPayer' => false])

<section id="invoice-items" class="card" @error('items') aria-invalid="true" tabindex="-1" @enderror>
    <div class="flex items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-bold">3. Položky</h2>
            <p class="mt-1 text-sm text-slate-600">Jednotková cena je bez DPH.</p>
        </div>
        <button class="button-secondary" type="button" @click="addItem">Přidat položku</button>
    </div>
    @error('items')<p class="field-error" role="alert">{{ $message }}</p>@enderror

    <div class="invoice-item-grid invoice-item-grid--{{ $isVatPayer ? 'vat' : 'non-vat' }} mt-5 hidden px-3 text-xs font-semibold uppercase tracking-wide text-slate-500 xl:grid" aria-hidden="true">
        <span>Popis</span><span>Množství</span><span>Jednotka</span><span>Cena/ks</span><span>Sleva</span><span>Hodnota</span>
        @if($isVatPayer)<span>DPH</span>@endif
        <span class="text-right">Cena položky</span><span class="text-center">Akce</span>
    </div>

    <div class="mt-3 space-y-3 xl:space-y-0 xl:divide-y xl:divide-slate-200 xl:rounded-xl xl:border xl:border-slate-200">
        <template x-for="(item,index) in items" :key="index">
            <fieldset :id="`item-${index}-position`" class="rounded-xl border border-slate-200 p-3 xl:rounded-none xl:border-0" :aria-invalid="hasFieldError(index,'position') ? 'true' : null" :tabindex="hasFieldError(index,'position') ? -1 : null">
                <legend class="px-2 font-semibold xl:sr-only" x-text="`Položka ${index+1}`"></legend>
                <input type="hidden" :name="`items[${index}][position]`" :value="index+1">
                <p class="field-error" x-show="fieldError(index,'position')" x-text="fieldError(index,'position')"></p>
                <div class="invoice-item-grid invoice-item-grid--{{ $isVatPayer ? 'vat' : 'non-vat' }} grid grid-cols-2 gap-3 xl:items-start">
                    <div class="col-span-2 xl:col-span-1">
                        <label class="xl:sr-only" :for="`item-${index}-description`">Popis *</label>
                        <input :id="`item-${index}-description`" :name="`items[${index}][description]`" x-model="item.description" maxlength="255" required :aria-invalid="hasFieldError(index,'description') ? 'true' : null" :aria-describedby="hasFieldError(index,'description') ? `item-${index}-description-error` : null">
                        <p class="field-error" :id="`item-${index}-description-error`" x-show="fieldError(index,'description')" x-text="fieldError(index,'description')"></p>
                    </div>
                    <div>
                        <label class="xl:sr-only" :for="`item-${index}-quantity`">Množství *</label>
                        <input :id="`item-${index}-quantity`" :name="`items[${index}][quantity]`" x-model="item.quantity" inputmode="decimal" required :aria-invalid="hasFieldError(index,'quantity') ? 'true' : null" :aria-describedby="hasFieldError(index,'quantity') ? `item-${index}-quantity-error` : null">
                        <p class="field-error" :id="`item-${index}-quantity-error`" x-show="fieldError(index,'quantity')" x-text="fieldError(index,'quantity')"></p>
                    </div>
                    <div>
                        <label class="xl:sr-only" :for="`item-${index}-unit`">Jednotka</label>
                        <input :id="`item-${index}-unit`" :name="`items[${index}][unit]`" x-model="item.unit" maxlength="32" :aria-invalid="hasFieldError(index,'unit') ? 'true' : null" :aria-describedby="hasFieldError(index,'unit') ? `item-${index}-unit-error` : null">
                        <p class="field-error" :id="`item-${index}-unit-error`" x-show="fieldError(index,'unit')" x-text="fieldError(index,'unit')"></p>
                    </div>
                    <div>
                        <label class="xl:sr-only" :for="`item-${index}-price`">Cena bez DPH *</label>
                        <input :id="`item-${index}-price`" :name="`items[${index}][unit_price]`" x-model="item.unit_price" inputmode="decimal" required :aria-invalid="hasFieldError(index,'unit_price') ? 'true' : null" :aria-describedby="hasFieldError(index,'unit_price') ? `item-${index}-price-error` : null">
                        <p class="field-error" :id="`item-${index}-price-error`" x-show="fieldError(index,'unit_price')" x-text="fieldError(index,'unit_price')"></p>
                    </div>
                    <div>
                        <label class="xl:sr-only" :for="`item-${index}-discount-type`">Sleva</label>
                        <select :id="`item-${index}-discount-type`" :name="`items[${index}][discount_type]`" x-model="item.discount_type" :aria-invalid="hasFieldError(index,'discount_type') ? 'true' : null" :aria-describedby="hasFieldError(index,'discount_type') ? `item-${index}-discount-type-error` : null">
                            @foreach($discountTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                        <p class="field-error" :id="`item-${index}-discount-type-error`" x-show="fieldError(index,'discount_type')" x-text="fieldError(index,'discount_type')"></p>
                    </div>
                    <div>
                        <label class="xl:sr-only" :for="`item-${index}-discount-value`">Hodnota slevy</label>
                        <input :id="`item-${index}-discount-value`" :name="`items[${index}][discount_value]`" x-model="item.discount_value" inputmode="decimal" :aria-invalid="hasFieldError(index,'discount_value') ? 'true' : null" :aria-describedby="hasFieldError(index,'discount_value') ? `item-${index}-discount-value-error` : null">
                        <p class="field-error" :id="`item-${index}-discount-value-error`" x-show="fieldError(index,'discount_value')" x-text="fieldError(index,'discount_value')"></p>
                    </div>
                    @if($isVatPayer)
                        <div>
                            <label class="xl:sr-only" :for="`item-${index}-vat`">Sazba DPH *</label>
                            <select :id="`item-${index}-vat`" :name="`items[${index}][vat_rate_uuid]`" x-model="item.vat_rate_uuid" required :aria-invalid="hasFieldError(index,'vat_rate_uuid') ? 'true' : null" :aria-describedby="hasFieldError(index,'vat_rate_uuid') ? `item-${index}-vat-error` : null">
                                <option value="">Vyberte sazbu</option>
                                @foreach($vatRates as $rate)<option value="{{ $rate->uuid }}">{{ $rate->name }}@if($rate->percentage !== null) · {{ \App\Domain\Invoices\InvoiceDecimal::format($rate->percentage) }} % @endif</option>@endforeach
                            </select>
                            <p class="field-error" :id="`item-${index}-vat-error`" x-show="fieldError(index,'vat_rate_uuid')" x-text="fieldError(index,'vat_rate_uuid')"></p>
                        </div>
                    @endif
                    <div class="flex min-h-10 flex-col justify-center text-right">
                        <span class="text-xs font-medium text-slate-500 xl:sr-only">Cena položky</span>
                        <output class="font-semibold tabular-nums" :class="loading ? 'text-slate-500' : 'text-slate-900'" x-text="`${money(previewLineTotal(index+1))}${previewLineTotal(index+1) == null ? '' : ` ${currency}`}`">—</output>
                        <span class="text-xs text-slate-400" x-show="loading">aktualizuji…</span>
                    </div>
                    <div class="col-span-2 flex items-start justify-end gap-1 xl:col-span-1 xl:justify-center">
                        <button type="button" class="button-secondary h-10 w-10 p-0 disabled:cursor-not-allowed disabled:opacity-40" @click="move(index,-1)" :disabled="index===0" :aria-label="`Posunout položku ${index+1} nahoru`" title="Posunout nahoru">↑</button>
                        <button type="button" class="button-secondary h-10 w-10 p-0 disabled:cursor-not-allowed disabled:opacity-40" @click="move(index,1)" :disabled="index===items.length-1" :aria-label="`Posunout položku ${index+1} dolů`" title="Posunout dolů">↓</button>
                        <button type="button" class="button-secondary h-10 w-10 p-0 text-lg text-red-700 disabled:cursor-not-allowed disabled:opacity-40" @click="removeItem(index)" :disabled="items.length===1" :aria-label="`Odebrat položku ${index+1}`" title="Odebrat položku">×</button>
                    </div>
                </div>
            </fieldset>
        </template>
    </div>
    <x-invoices.noscript-item :vat-rates="$vatRates" :discount-types="$discountTypes" :is-vat-payer="$isVatPayer" />
</section>
