<x-layouts.app title="Upravit návrh">
    <div class="mb-6"><p class="text-sm font-medium text-blue-700">Faktury</p><h1 class="mt-1 text-2xl font-bold">Upravit návrh</h1><p class="mt-2 text-sm text-slate-600">Revize {{ $revision->revision_number }} · verze {{ $invoice->version }}. Uložení skutečné změny vytvoří novou neměnnou revizi.</p></div>
    <x-invoices.form
        :action="route('invoices.update',$invoice->uuid)" method="PUT" submit-label="Uložit novou revizi"
        :invoice="$invoice" :revision="$revision" :correlation-uuid="$correlationUuid"
        :clients="$clients" :clients-truncated="$clientsTruncated" :bank-accounts="$bankAccounts"
        :vat-rates="$vatRates" :currencies="$currencies" :payment-methods="$paymentMethods"
        :discount-types="$discountTypes" :default-bank-accounts="$defaultBankAccounts"
        :default-vat-rate-uuid="$defaultVatRateUuid" />
</x-layouts.app>
