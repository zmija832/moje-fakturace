<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Přehled' }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="min-h-screen" x-data="{ menuOpen: false }">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3 font-bold">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-700 text-white" aria-hidden="true">F</span>
                    <span>{{ config('app.name') }}</span>
                </a>

                <button type="button" class="button-secondary lg:hidden" @click="menuOpen = ! menuOpen" :aria-expanded="menuOpen">
                    Menu
                </button>

                <div class="hidden items-center gap-3 lg:flex">
                    @if ($navigationBusinesses->isNotEmpty())
                        <div x-data="{ open: false }" class="relative">
                            <button type="button" class="button-secondary min-w-72 justify-between text-left" @click="open = ! open" @click.outside="open = false">
                                <span>
                                    <span class="block text-xs font-normal text-slate-500">Fakturační subjekt</span>
                                    <span class="block">
                                        {{ $navigationActiveBusiness?->short_label ?? 'Vyberte subjekt' }}
                                        @if ($navigationActiveBusiness)
                                            · IČO {{ $navigationActiveBusiness->registration_number }}
                                        @endif
                                    </span>
                                </span>
                                <span aria-hidden="true">⌄</span>
                            </button>

                            <div x-cloak x-show="open" class="absolute right-0 z-20 mt-2 w-80 rounded-xl border border-slate-200 bg-white p-2 shadow-xl">
                                @foreach ($navigationBusinesses as $business)
                                    <form method="POST" action="{{ route('business.switch', $business->uuid) }}">
                                        @csrf
                                        <button class="flex w-full items-start gap-3 rounded-lg px-3 py-3 text-left hover:bg-slate-50" type="submit">
                                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100" aria-hidden="true">
                                                {{ $business->visual_identifier === 'home-business' ? '⌂' : '▣' }}
                                            </span>
                                            <span>
                                                <span class="block font-semibold">{{ $business->display_name }}</span>
                                                <span class="block text-xs text-slate-500">IČO {{ $business->registration_number }}</span>
                                            </span>
                                            @if ($navigationActiveBusiness?->is($business))
                                                <span class="ml-auto text-sm font-semibold text-blue-700">Aktivní</span>
                                            @endif
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <span class="rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900">Není přiřazen žádný subjekt</span>
                    @endif

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="button-secondary">Odhlásit</button>
                    </form>
                </div>
            </div>
        </header>

        <div class="mx-auto flex max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:px-8">
            <aside class="hidden w-60 shrink-0 lg:block">
                <nav class="space-y-1" aria-label="Hlavní navigace">
                    @php
                        $items = [
                            ['route' => 'dashboard', 'label' => 'Přehled'],
                            ['route' => 'invoices.index', 'label' => 'Vydané faktury'],
                            ['route' => 'clients.index', 'label' => 'Klienti'],
                            ['route' => 'recurring.index', 'label' => 'Pravidelné fakturace'],
                            ['route' => 'exports.index', 'label' => 'Export'],
                            ...($navigationActiveBusiness ? [['route' => 'company-settings.edit', 'label' => 'Nastavení subjektu']] : []),
                            ['route' => 'settings', 'label' => 'Nastavení'],
                        ];
                    @endphp
                    @foreach ($items as $item)
                        <a href="{{ route($item['route']) }}"
                           class="block rounded-lg px-3 py-2 text-sm font-medium {{ request()->routeIs($item['route']) ? 'bg-blue-50 text-blue-800' : 'text-slate-700 hover:bg-slate-100' }}">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>
            </aside>

            <div x-cloak x-show="menuOpen" class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" @click.self="menuOpen = false">
                <div class="h-full w-80 max-w-[85vw] bg-white p-4 shadow-xl">
                    <div class="mb-4 flex items-center justify-between">
                        <strong>Navigace</strong>
                        <button class="button-secondary" @click="menuOpen = false">Zavřít</button>
                    </div>
                    <nav class="space-y-1">
                        <a href="{{ route('dashboard') }}" class="block rounded-lg px-3 py-2">Přehled</a>
                        <a href="{{ route('invoices.index') }}" class="block rounded-lg px-3 py-2">Vydané faktury</a>
                        <a href="{{ route('clients.index') }}" class="block rounded-lg px-3 py-2">Klienti</a>
                        <a href="{{ route('recurring.index') }}" class="block rounded-lg px-3 py-2">Pravidelné fakturace</a>
                        <a href="{{ route('exports.index') }}" class="block rounded-lg px-3 py-2">Export</a>
                        @if ($navigationActiveBusiness)
                            <a href="{{ route('company-settings.edit') }}" class="block rounded-lg px-3 py-2">Nastavení subjektu</a>
                        @endif
                        <a href="{{ route('settings') }}" class="block rounded-lg px-3 py-2">Nastavení</a>
                    </nav>
                    <form method="POST" action="{{ route('logout') }}" class="mt-5">
                        @csrf
                        <button type="submit" class="button-secondary w-full">Odhlásit</button>
                    </form>
                </div>
            </div>

            <main class="min-w-0 flex-1">
                @if (session('status'))
                    <div class="mb-5 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800" role="status">
                        {{ session('status') }}
                    </div>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
