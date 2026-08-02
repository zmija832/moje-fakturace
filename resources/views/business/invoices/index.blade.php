<x-layouts.app title="Faktury">
    @php $canCreate = auth()->user()->can('create', \App\Models\Business\Invoice::class); @endphp
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:justify-between">
        <div><p class="text-sm font-medium text-blue-700">Vydané doklady</p><h1 class="mt-1 text-2xl font-bold">Faktury</h1><p class="mt-2 text-sm text-slate-600">Návrhy a vystavené faktury aktivního subjektu.</p></div>
        @if($canCreate)<a class="button-primary" href="{{ route('invoices.create') }}">Nová faktura</a>@endif
    </div>
    <x-invoices.filters :filters="$filters" :clients="$clients" />
    @if($invoices->isEmpty())
        <section class="card text-center"><h2 class="font-bold">Žádné faktury</h2><p class="mt-2 text-sm text-slate-600">Pro zvolené filtry nebyl nalezen žádný doklad.</p></section>
    @else
        <x-invoices.listing :invoices="$invoices" />
        <p class="mt-3 text-xs text-slate-500">* Informativní označení bez evidence plateb; nejde o potvrzený dluh.</p>
        <div class="mt-6">{{ $invoices->links() }}</div>
    @endif
</x-layouts.app>
