@props(['action','method','submitLabel','clients','clientsTruncated'=>false,'bankAccounts','vatRates','currencies','paymentMethods','discountTypes','defaultBankAccounts','defaultVatRateUuid'=>null,'isVatPayer'=>false,'defaults'=>[],'invoice'=>null,'revision'=>null,'correlationUuid'=>null,'allowInlineClientCreation'=>false,'clientTypes'=>[],'countries'=>[]])
@php
    $values = $revision ? ['customer_uuid'=>$revision->customerSnapshot->source_client_uuid,'bank_account_uuid'=>$revision->bankAccountSnapshot?->source_bank_account_uuid,'currency'=>$revision->currency,'issued_on'=>$revision->issued_on->format('Y-m-d'),'taxable_supply_on'=>$revision->taxable_supply_on->format('Y-m-d'),'due_on'=>$revision->due_on->format('Y-m-d'),'payment_method'=>$revision->payment_method->value,'variable_symbol'=>$revision->variable_symbol,'note'=>$revision->note,'invoice_discount_type'=>$revision->invoice_discount_type->value,'invoice_discount_value'=>$revision->invoice_discount_value] : $defaults;
    $storedItems = $revision?->items->map(fn($item)=>['description'=>$item->description,'quantity'=>$item->quantity,'unit'=>$item->unit,'unit_price'=>$item->unit_price,'discount_type'=>$item->discount_type->value,'discount_value'=>$item->discount_value,...$isVatPayer ? ['vat_rate_uuid'=>$item->vatSnapshot->source_vat_rate_uuid] : []])->all();
    $emptyItem = ['description'=>'','quantity'=>'1','unit'=>'ks','unit_price'=>'0','discount_type'=>'none','discount_value'=>'0',...$isVatPayer ? ['vat_rate_uuid'=>$defaultVatRateUuid ?? ''] : []];
    $initialItems = old('items', $storedItems ?: [$emptyItem]);
    $canCreateClientInline = $allowInlineClientCreation && auth()->user()?->can('create', \App\Models\Business\Client::class);
    $editorConfig = ['items'=>$initialItems,'errors'=>$errors->toArray(),'previewUrl'=>route('invoices.preview'),'clientStoreUrl'=>$canCreateClientInline ? route('clients.store') : null,'aresLookupUrl'=>$canCreateClientInline ? route('clients.ares.lookup') : null,'csrf'=>csrf_token(),'isVatPayer'=>$isVatPayer,'defaultVatRateUuid'=>$isVatPayer ? $defaultVatRateUuid : null,'currency'=>old('currency',$values['currency'] ?? 'CZK'),'paymentMethod'=>old('payment_method',$values['payment_method'] ?? 'bank_transfer')];
    $errorLabels = ['customer_uuid'=>'Klient','bank_account_uuid'=>'Bankovní účet','currency'=>'Měna','payment_method'=>'Způsob úhrady','issued_on'=>'Datum vystavení','taxable_supply_on'=>'DUZP','due_on'=>'Datum splatnosti','variable_symbol'=>'Variabilní symbol','invoice_discount_type'=>'Celková sleva','invoice_discount_value'=>'Hodnota celkové slevy','note'=>'Poznámka','items'=>'Položky'];
    $itemErrorLabels = ['position'=>'Pořadí','description'=>'Popis','quantity'=>'Množství','unit'=>'Jednotka','unit_price'=>'Cena bez DPH','discount_type'=>'Sleva','discount_value'=>'Hodnota slevy','vat_rate_uuid'=>'Sazba DPH'];
    $errorTarget = static function (string $key) use ($errorLabels, $isVatPayer): string {
        if (preg_match('/^items\.(\d+)\.([a-z_]+)$/', $key, $matches) === 1) {
            if (! $isVatPayer && $matches[2] === 'vat_rate_uuid') {
                return 'invoice-items';
            }
            $suffixes = ['unit_price'=>'price','discount_type'=>'discount-type','discount_value'=>'discount-value','vat_rate_uuid'=>'vat'];

            return 'item-'.$matches[1].'-'.($suffixes[$matches[2]] ?? $matches[2]);
        }

        return $key === 'items' ? 'invoice-items' : (array_key_exists($key, $errorLabels) ? $key : 'invoice-form');
    };
    $errorLabel = static function (string $key) use ($errorLabels, $itemErrorLabels): string {
        if (preg_match('/^items\.(\d+)\.([a-z_]+)$/', $key, $matches) === 1) {
            return 'Položka '.((int) $matches[1] + 1).' – '.($itemErrorLabels[$matches[2]] ?? $matches[2]);
        }

        return $errorLabels[$key] ?? $key;
    };
@endphp
<div x-data="invoiceEditor({{ Illuminate\Support\Js::from($editorConfig) }})">
@if($errors->any())
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
        <p class="font-semibold">Formulář se nepodařilo uložit.</p>
        <p>Opravte označená pole. Zadané hodnoty zůstaly zachované.</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach($errors->getMessages() as $field => $messages)
                @foreach($messages as $message)
                    <li><a class="underline hover:no-underline" href="#{{ $errorTarget($field) }}" @click.prevent="focusErrorField('{{ $errorTarget($field) }}')"><span class="font-medium">{{ $errorLabel($field) }}:</span> {{ $message }}</a></li>
                @endforeach
            @endforeach
        </ul>
    </div>
@endif
@if($clientsTruncated)<div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">Zobrazeno je prvních 200 klientů. Upřesněte adresář před vytvořením faktury.</div>@endif
@if($isVatPayer && !$defaultVatRateUuid)<div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">Pro zvolené DUZP není nastavena výchozí sazba DPH. Vyberte ji ručně.</div>@endif
<form id="invoice-form" x-ref="form" method="POST" action="{{ $action }}" class="space-y-6" @input="queuePreview" @change="queuePreview">
    @csrf
    @if($method !== 'POST') @method($method) @endif
    @if($revision)<input type="hidden" name="version" value="{{ $invoice->version }}"><input type="hidden" name="correlation_uuid" value="{{ $correlationUuid }}">@endif
    <section class="card"><h2 class="mb-5 text-lg font-bold">1. Odběratel a platba</h2><div class="grid gap-5 md:grid-cols-2">
        <div>
            <label for="customer_uuid">Klient *</label>
            <div class="flex items-stretch gap-2">
                <select x-ref="customerSelect" id="customer_uuid" name="customer_uuid" required @change="applyClient"
                    @error('customer_uuid') aria-invalid="true" aria-describedby="customer_uuid-error" @enderror>
                    <option value="">Vyberte klienta</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->uuid }}" data-currency="{{ $client->default_currency }}" data-due-days="{{ $client->default_due_days }}" data-payment-method="{{ $client->default_payment_method }}" @selected(old('customer_uuid',$values['customer_uuid'] ?? '') === $client->uuid)>
                            {{ $client->display_name }}@if($client->registration_number) · IČO {{ $client->registration_number }}@endif
                        </option>
                    @endforeach
                </select>
                @if($canCreateClientInline)
                    <button type="button" class="button-secondary shrink-0 px-3 text-lg" aria-label="Vytvořit nového klienta" title="Vytvořit nového klienta" @click="openQuickClient">➕</button>
                @endif
            </div>
            @error('customer_uuid')
                <p id="customer_uuid-error" class="field-error">{{ $message }}</p>
            @enderror
            @if($canCreateClientInline)
                <p x-cloak x-show="quickClientSuccess" x-text="quickClientSuccess" class="mt-2 rounded-lg bg-green-50 px-3 py-2 text-sm font-medium text-green-800" role="status"></p>
            @endif
        </div>
        <div><label for="currency">Měna *</label><select id="currency" name="currency" x-model="currency" required @error('currency') aria-invalid="true" aria-describedby="currency-error" @enderror>@foreach($currencies as $value=>$label)<option value="{{ $value }}" @selected(old('currency',$values['currency'] ?? 'CZK') === $value)>{{ $label }}</option>@endforeach</select>@error('currency')<p id="currency-error" class="field-error">{{ $message }}</p>@enderror</div>
        <div><label for="payment_method">Způsob úhrady *</label><select id="payment_method" name="payment_method" x-model="paymentMethod" required @error('payment_method') aria-invalid="true" aria-describedby="payment_method-error" @enderror>@foreach($paymentMethods as $value=>$label)<option value="{{ $value }}" @selected(old('payment_method',$values['payment_method'] ?? 'bank_transfer') === $value)>{{ $label }}</option>@endforeach</select>@error('payment_method')<p id="payment_method-error" class="field-error">{{ $message }}</p>@enderror</div>
        <div x-show="paymentMethod === 'bank_transfer'"><label for="bank_account_uuid">Bankovní účet *</label><select id="bank_account_uuid" name="bank_account_uuid" @error('bank_account_uuid') aria-invalid="true" aria-describedby="bank_account_uuid-error" @enderror><option value="">Vyberte účet</option>@foreach($bankAccounts as $account)<option value="{{ $account->uuid }}" data-currency="{{ $account->currency }}" x-show="currency === '{{ $account->currency }}'" :disabled="currency !== '{{ $account->currency }}'" @selected(old('bank_account_uuid',$values['bank_account_uuid'] ?? ($defaultBankAccounts[old('currency',$values['currency'] ?? 'CZK')] ?? '')) === $account->uuid)>{{ $account->name }} · {{ $account->currency }}</option>@endforeach</select>@error('bank_account_uuid')<p id="bank_account_uuid-error" class="field-error">{{ $message }}</p>@enderror</div>
    </div></section>
    <x-invoices.header-fields :values="$values" :discount-types="$discountTypes" />
    <x-invoices.items-editor :vat-rates="$vatRates" :discount-types="$discountTypes" :is-vat-payer="$isVatPayer" />
    <x-invoices.preview-panel />
    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><a class="button-secondary" href="{{ $invoice ? route('invoices.show',$invoice->uuid) : route('invoices.index') }}">Zrušit</a><button class="button-primary" type="submit">{{ $submitLabel }}</button></div>
</form>
@if($canCreateClientInline)
    <x-clients.quick-create-modal :client-types="$clientTypes" :countries="$countries" />
@endif
</div>
