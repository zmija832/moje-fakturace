@props(['vatRates', 'discountTypes', 'isVatPayer' => false])

<section id="invoice-items" class="card" @error('items') aria-invalid="true" tabindex="-1" @enderror>
    <div>
        <h2 class="text-lg font-bold">3. Položky</h2>
        <p class="mt-1 text-sm text-slate-600">Jednotková cena je bez DPH.</p>
    </div>
    @error('items')<p class="field-error" role="alert">{{ $message }}</p>@enderror

    <div class="invoice-editor-row invoice-editor-row--{{ $isVatPayer ? 'vat' : 'non-vat' }} mt-5 hidden border-b border-slate-200 px-2 pb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 xl:grid" aria-hidden="true">
        <span></span><span>Množství</span><span>MJ</span><span>Popis</span><span>Cena za MJ</span><span>Sleva</span>
        @if($isVatPayer)<span>DPH</span>@endif
        <span class="text-right">Celkem</span><span></span>
    </div>

    <div class="mt-2 divide-y divide-slate-200">
        <template x-for="(item,index) in items" :key="item._editorKey">
            <div
                :id="`item-${index}-position`"
                class="invoice-editor-row invoice-editor-row--{{ $isVatPayer ? 'vat' : 'non-vat' }} grid grid-cols-2 gap-3 py-3 xl:items-start xl:px-2"
                :class="draggedItemIndex === index ? 'opacity-50' : ''"
                :aria-invalid="hasFieldError(index,'position') ? 'true' : null"
                :tabindex="hasFieldError(index,'position') ? -1 : null"
                @dragover.prevent
                @drop.prevent="dropItem(index)"
            >
                <input type="hidden" :name="`items[${index}][position]`" :value="index+1">
                <div class="hidden xl:flex xl:justify-center">
                    <button
                        type="button"
                        class="inline-flex h-10 w-8 cursor-grab items-center justify-center rounded-md text-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-300 active:cursor-grabbing"
                        draggable="true"
                        @dragstart="startItemDrag($event,index)"
                        @dragend="endItemDrag"
                        @keydown.up.prevent="moveItemByOffset(index,-1)"
                        @keydown.down.prevent="moveItemByOffset(index,1)"
                        :aria-label="`Přesunout položku ${index+1}`"
                        title="Přetažením změnit pořadí"
                    >⠿</button>
                </div>
                <div>
                    <label class="text-xs xl:sr-only" :for="`item-${index}-quantity`">Množství *</label>
                    <input :id="`item-${index}-quantity`" :name="`items[${index}][quantity]`" x-model="item.quantity" inputmode="decimal" required :aria-invalid="hasFieldError(index,'quantity') ? 'true' : null" :aria-describedby="hasFieldError(index,'quantity') ? `item-${index}-quantity-error` : null">
                    <p class="field-error" :id="`item-${index}-quantity-error`" x-show="fieldError(index,'quantity')" x-text="fieldError(index,'quantity')"></p>
                    <p class="field-error" x-show="fieldError(index,'position')" x-text="fieldError(index,'position')"></p>
                </div>
                <div>
                    <label class="text-xs xl:sr-only" :for="`item-${index}-unit`">MJ</label>
                    <input :id="`item-${index}-unit`" :name="`items[${index}][unit]`" x-model="item.unit" maxlength="32" :aria-invalid="hasFieldError(index,'unit') ? 'true' : null" :aria-describedby="hasFieldError(index,'unit') ? `item-${index}-unit-error` : null">
                    <p class="field-error" :id="`item-${index}-unit-error`" x-show="fieldError(index,'unit')" x-text="fieldError(index,'unit')"></p>
                </div>
                <div class="col-span-2 xl:col-span-1">
                    <label class="text-xs xl:sr-only" :for="`item-${index}-description`">Popis *</label>
                    <input :id="`item-${index}-description`" :name="`items[${index}][description]`" x-model="item.description" maxlength="255" required :aria-invalid="hasFieldError(index,'description') ? 'true' : null" :aria-describedby="hasFieldError(index,'description') ? `item-${index}-description-error` : null">
                    <p class="field-error" :id="`item-${index}-description-error`" x-show="fieldError(index,'description')" x-text="fieldError(index,'description')"></p>
                </div>
                <div>
                    <label class="text-xs xl:sr-only" :for="`item-${index}-price`">Cena za MJ *</label>
                    <input :id="`item-${index}-price`" :name="`items[${index}][unit_price]`" x-model="item.unit_price" inputmode="decimal" required :aria-invalid="hasFieldError(index,'unit_price') ? 'true' : null" :aria-describedby="hasFieldError(index,'unit_price') ? `item-${index}-price-error` : null">
                    <p class="field-error" :id="`item-${index}-price-error`" x-show="fieldError(index,'unit_price')" x-text="fieldError(index,'unit_price')"></p>
                </div>
                <div class="col-span-2 xl:col-span-1">
                    <label class="text-xs xl:sr-only" :for="`item-${index}-discount-type`">Sleva</label>
                    <div class="flex gap-2">
                        <select class="min-w-0" :class="item.discount_type === 'none' ? 'w-full' : 'w-3/5'" :id="`item-${index}-discount-type`" :name="`items[${index}][discount_type]`" x-model="item.discount_type" :aria-invalid="hasFieldError(index,'discount_type') ? 'true' : null" :aria-describedby="hasFieldError(index,'discount_type') ? `item-${index}-discount-type-error` : null">
                            @foreach($discountTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                        <input class="min-w-0 w-2/5" x-show="item.discount_type !== 'none' || hasFieldError(index,'discount_value')" :id="`item-${index}-discount-value`" :name="`items[${index}][discount_value]`" x-model="item.discount_value" inputmode="decimal" aria-label="Hodnota slevy" :aria-invalid="hasFieldError(index,'discount_value') ? 'true' : null" :aria-describedby="hasFieldError(index,'discount_value') ? `item-${index}-discount-value-error` : null">
                    </div>
                    <p class="field-error" :id="`item-${index}-discount-type-error`" x-show="fieldError(index,'discount_type')" x-text="fieldError(index,'discount_type')"></p>
                    <p class="field-error" :id="`item-${index}-discount-value-error`" x-show="fieldError(index,'discount_value')" x-text="fieldError(index,'discount_value')"></p>
                </div>
                @if($isVatPayer)
                    <div>
                        <label class="text-xs xl:sr-only" :for="`item-${index}-vat`">DPH *</label>
                        <select :id="`item-${index}-vat`" :name="`items[${index}][vat_rate_uuid]`" x-model="item.vat_rate_uuid" required :aria-invalid="hasFieldError(index,'vat_rate_uuid') ? 'true' : null" :aria-describedby="hasFieldError(index,'vat_rate_uuid') ? `item-${index}-vat-error` : null">
                            <option value="">Vyberte</option>
                            @foreach($vatRates as $rate)<option value="{{ $rate->uuid }}">{{ $rate->name }}@if($rate->percentage !== null) · {{ \App\Domain\Invoices\InvoiceDecimal::format($rate->percentage) }} % @endif</option>@endforeach
                        </select>
                        <p class="field-error" :id="`item-${index}-vat-error`" x-show="fieldError(index,'vat_rate_uuid')" x-text="fieldError(index,'vat_rate_uuid')"></p>
                    </div>
                @endif
                <div class="flex min-h-10 flex-col justify-center text-right">
                    <span class="text-xs font-medium text-slate-500 xl:sr-only">Celkem</span>
                    <output class="font-bold tabular-nums" :class="loading ? 'text-slate-500' : 'text-slate-900'" x-text="`${money(previewLineTotal(index+1))}${previewLineTotal(index+1) == null ? '' : ` ${currency}`}`">—</output>
                    <span class="text-xs text-slate-400" x-show="loading">aktualizuji…</span>
                </div>
                <div class="flex items-start justify-end">
                    <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-md text-xl text-slate-400 hover:bg-red-50 hover:text-red-700 focus:outline-none focus:ring-2 focus:ring-red-200 disabled:cursor-not-allowed disabled:opacity-30" @click="removeItem(index)" :disabled="items.length===1" :aria-label="`Odebrat položku ${index+1}`" title="Odebrat položku">×</button>
                </div>
            </div>
        </template>
    </div>

    <div class="mt-4 border-t border-slate-200 pt-4">
        <button class="button-secondary" type="button" @click="addItem">Přidat položku</button>
    </div>
    <x-invoices.noscript-item :vat-rates="$vatRates" :discount-types="$discountTypes" :is-vat-payer="$isVatPayer" />
</section>
