<x-layouts.app>
    <x-slot:title>Upravit sazbu DPH</x-slot:title>
    <div class="mb-5 flex items-center justify-between gap-4">
        <div><h1 class="text-2xl font-bold">Upravit sazbu DPH</h1><p class="mt-1 text-sm text-slate-600">{{ $rate->name }} · {{ $rate->code }}</p></div>
        <a class="button-secondary" href="{{ route('vat-rates.show', $rate->uuid) }}">Zpět</a>
    </div>
    <form method="POST" action="{{ route('vat-rates.update', $rate->uuid) }}">
        @csrf @method('PUT')
        @include('business.vat-rates._form')
        <div class="mt-5 flex justify-end"><button class="button-primary" type="submit">Uložit změny</button></div>
    </form>
</x-layouts.app>
