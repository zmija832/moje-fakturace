<x-layouts.app title="Upravit bankovní účet">
    <div class="mb-6">
        <p class="text-sm font-medium text-blue-700">Bankovní účty</p>
        <h1 class="mt-1 text-2xl font-bold">Upravit bankovní účet</h1>
    </div>

    @include('business.bank-accounts._form', [
        'account' => $account,
        'currencies' => $currencies,
        'action' => route('bank-accounts.update', $account->uuid),
        'method' => 'PUT',
        'submitLabel' => 'Uložit účet',
    ])
</x-layouts.app>
