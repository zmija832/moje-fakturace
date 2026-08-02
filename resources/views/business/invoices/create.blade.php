<x-layouts.app title="Nová faktura">
    <div class="mb-6"><p class="text-sm font-medium text-blue-700">Faktury</p><h1 class="mt-1 text-2xl font-bold">Nová faktura</h1><p class="mt-2 text-sm text-slate-600">Faktura vznikne jako návrh bez čísla dokladu.</p></div>
    <x-invoices.form
        :action="route('invoices.store')" method="POST" submit-label="Vytvořit návrh"
        :defaults="$defaults" :clients="$clients" :clients-truncated="$clientsTruncated"
        :bank-accounts="$bankAccounts" :vat-rates="$vatRates" :currencies="$currencies"
        :payment-methods="$paymentMethods" :discount-types="$discountTypes"
        :default-bank-accounts="$defaultBankAccounts" :default-vat-rate-uuid="$defaultVatRateUuid" />
</x-layouts.app>
