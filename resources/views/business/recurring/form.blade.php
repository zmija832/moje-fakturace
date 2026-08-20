@php
    $editing = $template->exists;
    $hasOldItems = old('items') !== null;
    $items = old('items', $template->relationLoaded('items')
        ? $template->items->toArray()
        : [['description' => '', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '0', 'discount_type' => 'none', 'discount_value' => null, 'vat_rate_uuid' => null]]);
    $selectedCurrency = old('currency', $template->currency ?: 'CZK');
    $selectedBankAccount = old('bank_account_uuid', $editing
        ? $template->bank_account_uuid
        : ($options['defaultBankAccounts'][$selectedCurrency] ?? null));
@endphp
<x-layouts.app :title="$editing ? 'Upravit opakovanou fakturu' : 'Nová opakovaná faktura'">
    <h1 class="text-3xl font-bold">{{ $editing ? 'Upravit opakovanou fakturu' : 'Nová opakovaná faktura' }}</h1>
    <form class="card mt-6 space-y-5" method="POST" action="{{ $editing ? route('recurring.update', $template->uuid) : route('recurring.store') }}"
        x-data="{ currency: @js($selectedCurrency), defaults: @js($options['defaultBankAccounts']) }">
        @csrf
        @if($editing) @method('PUT') @endif
        <div class="grid gap-4 md:grid-cols-2">
            <label>Název<input class="input mt-1" name="name" required value="{{ old('name', $template->name) }}"></label>
            <label>Klient<select class="input mt-1" name="client_uuid" required><option value="">Vyberte</option>@foreach($options['clients'] as $client)<option value="{{ $client->uuid }}" @selected(old('client_uuid', $template->client_uuid) === $client->uuid)>{{ $client->display_name }}</option>@endforeach</select></label>
            <label>Měna<select class="input mt-1" name="currency" x-model="currency" @change="$refs.bankAccount.value = defaults[currency] ?? ''">@foreach($options['currencies'] as $code => $label)<option value="{{ $code }}" @selected($selectedCurrency === $code)>{{ $label }}</option>@endforeach</select></label>
            <label>Bankovní účet<select x-ref="bankAccount" class="input mt-1" name="bank_account_uuid"><option value="">Bez účtu</option>@foreach($options['bankAccounts'] as $account)<option value="{{ $account->uuid }}" x-show="currency === '{{ $account->currency }}'" :disabled="currency !== '{{ $account->currency }}'" @selected($selectedBankAccount === $account->uuid)>{{ $account->name }} · {{ $account->currency }}</option>@endforeach</select></label>
            <label>Splatnost (dnů)<input class="input mt-1" type="number" min="0" max="365" name="due_days" value="{{ old('due_days', $template->due_days ?? 14) }}"></label>
            <label>Periodicita<select class="input mt-1" name="interval_months">@foreach([1 => 'Měsíčně', 3 => 'Čtvrtletně', 6 => 'Pololetně', 12 => 'Ročně'] as $months => $label)<option value="{{ $months }}" @selected((int) old('interval_months', $template->interval_months ?? 1) === $months)>{{ $label }}</option>@endforeach</select></label>
            <label>Další vytvoření<input class="input mt-1" type="date" name="next_run_on" value="{{ old('next_run_on', $template->next_run_on?->format('Y-m-d') ?? $options['businessToday']) }}"></label>
            <label>Režim<select class="input mt-1" name="mode"><option value="draft" @selected(old('mode', $template->mode) === 'draft')>Vytvořit koncept</option><option value="auto_issue" @selected(old('mode', $template->mode) === 'auto_issue')>Automaticky vystavit</option></select></label>
            <label>Způsob úhrady<select class="input mt-1" name="payment_method">@foreach($options['paymentMethods'] as $code => $label)<option value="{{ $code }}" @selected(old('payment_method', $template->payment_method ?: 'bank_transfer') === $code)>{{ $label }}</option>@endforeach</select></label>
            <label class="flex items-center gap-2"><input type="checkbox" name="auto_send" value="1" @checked(old('auto_send', $template->auto_send))> Po vystavení odeslat klientovi</label>
        </div>

        <section>
            <h2 class="font-bold">Položky</h2>
            <div id="recurring-items" class="space-y-2">
                @foreach($items as $index => $item)
                    <div class="recurring-item grid gap-2 rounded border p-3 md:grid-cols-8">
                        <input class="input md:col-span-2" name="items[{{ $index }}][description]" required placeholder="Popis" value="{{ $item['description'] ?? '' }}">
                        <input class="input" name="items[{{ $index }}][quantity]" required aria-label="Množství" value="{{ $hasOldItems ? ($item['quantity'] ?? '1') : \App\Domain\Invoices\InvoiceDecimal::formatInput($item['quantity'] ?? '1') }}">
                        <input class="input" name="items[{{ $index }}][unit]" aria-label="MJ" value="{{ $item['unit'] ?? 'ks' }}">
                        <input class="input" name="items[{{ $index }}][unit_price]" required aria-label="Cena" value="{{ $hasOldItems ? ($item['unit_price'] ?? '0') : \App\Domain\Invoices\InvoiceDecimal::formatInput($item['unit_price'] ?? '0') }}">
                        <select class="input" name="items[{{ $index }}][discount_type]" aria-label="Typ slevy"><option value="none">Bez slevy</option><option value="percentage" @selected(($item['discount_type'] ?? '') === 'percentage')>%</option><option value="fixed" @selected(($item['discount_type'] ?? '') === 'fixed')>Částka</option></select>
                        <input class="input" name="items[{{ $index }}][discount_value]" aria-label="Hodnota slevy" placeholder="Sleva" value="{{ $hasOldItems || ($item['discount_value'] ?? null) === null ? ($item['discount_value'] ?? '') : \App\Domain\Invoices\InvoiceDecimal::formatInput($item['discount_value']) }}">
                        @if($options['isVatPayer'])<select class="input" name="items[{{ $index }}][vat_rate_uuid]" aria-label="DPH">@foreach($options['vatRates'] as $rate)<option value="{{ $rate->uuid }}" @selected(($item['vat_rate_uuid'] ?? null) === $rate->uuid)>{{ $rate->name }}</option>@endforeach</select>@endif
                        <button class="button-secondary recurring-remove" type="button" aria-label="Odebrat položku">×</button>
                    </div>
                @endforeach
            </div>
            <button id="recurring-add-item" class="button-secondary mt-3" type="button">Přidat položku</button>
        </section>
        <label>Poznámka<textarea class="input mt-1" name="note">{{ old('note', $template->note) }}</textarea></label>
        @if($errors->any())<div class="rounded bg-red-50 p-3 text-red-800">{{ implode(' ', array_unique($errors->all())) }}</div>@endif
        <button class="btn-primary">Uložit</button>
    </form>

    <template id="recurring-item-template">
        <div class="recurring-item grid gap-2 rounded border p-3 md:grid-cols-8">
            <input class="input md:col-span-2" name="items[__INDEX__][description]" required placeholder="Popis"><input class="input" name="items[__INDEX__][quantity]" required aria-label="Množství" value="1"><input class="input" name="items[__INDEX__][unit]" aria-label="MJ" value="ks"><input class="input" name="items[__INDEX__][unit_price]" required aria-label="Cena" value="0"><select class="input" name="items[__INDEX__][discount_type]" aria-label="Typ slevy"><option value="none">Bez slevy</option><option value="percentage">%</option><option value="fixed">Částka</option></select><input class="input" name="items[__INDEX__][discount_value]" aria-label="Hodnota slevy" placeholder="Sleva">@if($options['isVatPayer'])<select class="input" name="items[__INDEX__][vat_rate_uuid]" aria-label="DPH">@foreach($options['vatRates'] as $rate)<option value="{{ $rate->uuid }}">{{ $rate->name }}</option>@endforeach</select>@endif<button class="button-secondary recurring-remove" type="button" aria-label="Odebrat položku">×</button>
        </div>
    </template>
</x-layouts.app>
