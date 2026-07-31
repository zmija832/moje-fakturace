<x-layouts.app title="Nový klient">
    <div class="mb-6"><p class="text-sm font-medium text-blue-700">Klienti</p><h1 class="mt-1 text-2xl font-bold">Nový klient</h1></div>
    @include('business.clients._form', ['action' => route('clients.store'), 'method' => 'POST', 'submitLabel' => 'Vytvořit klienta'])
</x-layouts.app>
