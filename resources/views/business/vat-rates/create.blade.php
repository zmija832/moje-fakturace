<x-layouts.app>
    <x-slot:title>Nová sazba DPH</x-slot:title>
    <div class="mb-5 flex items-center justify-between gap-4">
        <div><h1 class="text-2xl font-bold">Nová sazba DPH</h1><p class="mt-1 text-sm text-slate-600">Konfigurační sazba pro budoucí doklady.</p></div>
        <a class="button-secondary" href="{{ route('vat-rates.index') }}">Zpět</a>
    </div>
    <form method="POST" action="{{ route('vat-rates.store') }}">
        @csrf
        @include('business.vat-rates._form')
        <div class="mt-5 flex justify-end"><button class="button-primary" type="submit">Vytvořit sazbu</button></div>
    </form>
</x-layouts.app>
