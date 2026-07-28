<x-layouts.app title="Přehled">
    <div class="mb-6">
        <p class="text-sm font-medium text-blue-700">Přehled</p>
        <h1 class="mt-1 text-3xl font-bold">Vítejte, {{ $user->name }}</h1>
    </div>

    @if ($activeBusiness)
        <section class="card">
            <p class="text-sm font-medium text-slate-500">Aktivní fakturační subjekt</p>
            <div class="mt-3 flex items-start gap-4">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-xl text-blue-800" aria-hidden="true">
                    {{ $activeBusiness->visual_identifier === 'home-business' ? '⌂' : '▣' }}
                </span>
                <div>
                    <h2 class="text-xl font-bold">{{ $activeBusiness->display_name }}</h2>
                    <p class="mt-1 text-slate-600">IČO {{ $activeBusiness->registration_number }}</p>
                    <p class="mt-4 text-sm text-slate-600">Klienti, faktury a další účetní moduly budou doplněny v následujících etapách.</p>
                </div>
            </div>
        </section>
    @else
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <h2 class="font-bold text-amber-950">Není přiřazen fakturační subjekt</h2>
            <p class="mt-2 text-sm text-amber-900">Účetní části aplikace jsou bezpečně zablokované. Spusťte instalační příkaz pro nastavení subjektů a oprávnění.</p>
        </section>
    @endif
</x-layouts.app>
