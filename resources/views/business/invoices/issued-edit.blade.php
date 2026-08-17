<x-layouts.app :title="'Upravit vystavenou fakturu '.$invoice->document_number">
    <div class="mb-6">
        <p class="text-sm font-medium text-red-700">Admin úprava vystaveného dokladu</p>
        <h1 class="mt-1 text-2xl font-bold">{{ $invoice->document_number }}</h1>
        <p class="mt-2 text-sm text-slate-700">Revize {{ $revision->revision_number }} · verze {{ $invoice->version }}. Uložení skutečné změny vytvoří novou immutable issued revizi a nové PDF; číslo faktury se nezmění.</p>
    </div>
    <div class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 text-sm font-semibold text-red-900">Upravujete již vystavený doklad. Stará revize ani její PDF nebudou přepsány.</div>
    <x-invoices.form
        :action="route('invoices.issued-update',$invoice->uuid)" method="PUT" submit-label="Uložit novou issued revizi"
        :invoice="$invoice" :revision="$revision" :correlation-uuid="$correlationUuid" :issued-edit="true"
        :clients="$clients" :clients-truncated="$clientsTruncated" :bank-accounts="$bankAccounts"
        :vat-rates="$vatRates" :currencies="$currencies" :payment-methods="$paymentMethods"
        :discount-types="$discountTypes" :default-bank-accounts="$defaultBankAccounts"
        :default-vat-rate-uuid="$defaultVatRateUuid" :is-vat-payer="$isVatPayer" />
</x-layouts.app>