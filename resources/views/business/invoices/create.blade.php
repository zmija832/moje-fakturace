<x-layouts.app title="Nová faktura">
    <div class="mb-6"><p class="text-sm font-medium text-blue-700">Faktury</p><h1 class="mt-1 text-2xl font-bold">Nová faktura</h1><p class="mt-2 text-sm text-slate-600">Fakturu můžete rovnou vystavit, nebo ji uložit jako koncept pro pozdější úpravy.</p></div>
    <x-invoices.form
        :action="route('invoices.store')" method="POST" submit-label="Vytvořit fakturu" :show-create-actions="true"
        :defaults="$defaults" :clients="$clients" :clients-truncated="$clientsTruncated"
        :bank-accounts="$bankAccounts" :vat-rates="$vatRates" :currencies="$currencies"
        :client-types="$clientTypes" :countries="$countries" :allow-inline-client-creation="true"
        :payment-methods="$paymentMethods" :discount-types="$discountTypes"
        :default-bank-accounts="$defaultBankAccounts" :default-vat-rate-uuid="$defaultVatRateUuid"
        :is-vat-payer="$isVatPayer" />
</x-layouts.app>
