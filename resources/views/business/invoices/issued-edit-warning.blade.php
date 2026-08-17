<x-layouts.app :title="'Admin úprava '.$invoice->document_number">
    <div class="mx-auto max-w-2xl">
        <section class="rounded-xl border-2 border-red-300 bg-red-50 p-6 text-red-950">
            <p class="text-sm font-bold uppercase tracking-wide">Významná účetní operace</p>
            <h1 class="mt-2 text-2xl font-bold">Upravit vystavenou fakturu {{ $invoice->document_number }}?</h1>
            <p class="mt-4">Vytvoří se nová neměnná issued revize a nová verze PDF. Původní revize, snapshoty, číslo dokladu, audit i staré PDF zůstanou zachované.</p>
            <p class="mt-3 font-semibold">Tato funkce nenahrazuje storno ani dobropis. Pokračujte pouze při oprávněné administrativní opravě.</p>
            <form method="POST" action="{{ route('invoices.issued-edit.confirm', $invoice->uuid) }}" class="mt-6 flex flex-wrap gap-3">
                @csrf
                <button class="button-primary" type="submit">Rozumím, pokračovat k úpravě</button>
                <a class="button-secondary" href="{{ route('invoices.show', $invoice->uuid) }}">Zrušit</a>
            </form>
        </section>
    </div>
</x-layouts.app>