<x-layouts.app :title="'Upravit · '.$client->display_name">
    <div class="mb-6"><p class="text-sm font-medium text-blue-700">Klienti</p><h1 class="mt-1 text-2xl font-bold">Upravit klienta</h1><p class="mt-2 text-sm text-slate-600">{{ $client->display_name }}</p></div>
    @include('business.clients._form', ['action' => route('clients.update', $client->uuid), 'method' => 'PUT', 'submitLabel' => 'Uložit změny'])
</x-layouts.app>
