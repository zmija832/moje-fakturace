<x-layouts.app title="Nový bankovní účet">
    <div class="mb-6">
        <p class="text-sm font-medium text-blue-700">Bankovní účty</p>
        <h1 class="mt-1 text-2xl font-bold">Nový bankovní účet</h1>
    </div>

    @include('business.bank-accounts._form', [
        'account' => $account,
        'currencies' => $currencies,
        'action' => route('bank-accounts.store'),
        'method' => 'POST',
        'submitLabel' => 'Vytvořit účet',
    ])
</x-layouts.app>
