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
    @php
        $navigationItems = [
            ['route' => 'dashboard', 'active' => 'dashboard', 'label' => 'Přehled'],
            ...($navigationActiveBusiness ? [
                ['route' => 'invoices.index', 'active' => 'invoices.*', 'label' => 'Faktury'],
                ['route' => 'clients.index', 'active' => 'clients.*', 'label' => 'Klienti'],
                ['route' => 'invoice-catalog.index', 'active' => 'invoice-catalog.*', 'label' => 'Položky'],
                ['route' => 'invoice-labels.index', 'active' => 'invoice-labels.*', 'label' => 'Štítky faktur'],
                ['route' => 'recurring.index', 'active' => 'recurring.*', 'label' => 'Pravidelné fakturace'],
                ['route' => 'exports.index', 'active' => 'exports.*', 'label' => 'Export'],
                ['route' => 'company-settings.edit', 'active' => 'company-settings.*', 'label' => 'Nastavení subjektu'],
                ['route' => 'invoice-email-settings.edit', 'active' => 'invoice-email-settings.*', 'label' => 'E-maily'],
                ['route' => 'automation-settings.edit', 'active' => 'automation-settings.*', 'label' => 'Upomínky a automatizace'],
                ['route' => 'bank-accounts.index', 'active' => 'bank-accounts.*', 'label' => 'Bankovní účty'],
                ['route' => 'bank-transactions.index', 'active' => 'bank-transactions.*', 'label' => 'Bankovní platby'],
                ['route' => 'document-sequences.index', 'active' => 'document-sequences.*', 'label' => 'Číselné řady'],
                ['route' => 'vat-rates.index', 'active' => 'vat-rates.*', 'label' => 'Sazby DPH'],
                ['route' => 'business-audit.index', 'active' => 'business-audit.*', 'label' => 'Audit změn'],
            ] : []),
            ['route' => 'settings', 'active' => 'settings', 'label' => 'Nastavení'],
        ];
        $businessThemeColor = \App\Enums\BusinessThemeColor::tryFrom((string) $navigationActiveBusiness?->theme_color)
            ?? \App\Enums\BusinessThemeColor::Blue;
        $businessTheme = $businessThemeColor->theme();
    @endphp

    <div class="min-h-screen" data-business-theme="{{ $businessThemeColor->value }}" x-data="{ menuOpen: false }" style="background-color: {{ $businessTheme['page'] }}">
        <header class="border-b" style="border-color: {{ $businessTheme['border'] }}; background-color: {{ $businessTheme['page'] }}">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
                <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 font-bold">
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-700 text-white" aria-hidden="true">F</span>
                        <span>{{ config('app.name') }}</span>
                    </a>
                    @auth
                        <span class="hidden max-w-52 truncate text-base font-semibold uppercase tracking-wide text-slate-700 lg:inline-block lg:max-w-72" data-login-user-desktop title="Přihlášený uživatel: {{ auth()->user()->name }}">{{ auth()->user()->name }}</span>
                    @endauth
                </div>

                <button type="button" class="button-secondary lg:hidden" @click="menuOpen = ! menuOpen" :aria-expanded="menuOpen">
                    Menu
                </button>

                @if($navigationActiveBusiness)
                    <div class="order-3 flex w-full items-center gap-3 rounded-xl border px-3 py-2 lg:hidden" data-active-business-mobile data-active-business-name="{{ $navigationActiveBusiness->display_name }}" style="background-color: {{ $businessTheme['background'] }}; border-color: {{ $businessTheme['border'] }}; color: {{ $businessTheme['text'] }}">
                        <span class="h-3 w-3 shrink-0 rounded-full" style="background-color: {{ $businessTheme['accent'] }}" aria-hidden="true"></span>
                        <span class="min-w-0"><span class="block text-[11px] font-semibold uppercase tracking-wide">Aktivní subjekt</span><span class="block truncate font-bold">{{ $navigationActiveBusiness->display_name }}</span></span>
                    </div>
                @endif

                <div class="hidden items-center gap-3 lg:flex">
                    @if ($navigationBusinesses->isNotEmpty())
                        <div x-data="{ open: false }" class="relative">
                            <button type="button" class="inline-flex min-w-72 items-center justify-between rounded-lg border px-4 py-2 text-left text-sm font-semibold shadow-sm" data-active-business-desktop data-active-business-name="{{ $navigationActiveBusiness?->display_name }}" style="background-color: {{ $businessTheme['background'] }}; border-color: {{ $businessTheme['border'] }}; color: {{ $businessTheme['text'] }}" @click="open = ! open" @click.outside="open = false">
                                <span>
                                    <span class="block text-xs font-normal text-slate-500">Fakturační subjekt</span>
                                    <span class="block">
                                        {{ $navigationActiveBusiness?->display_name ?? 'Vyberte subjekt' }}
                                        @if ($navigationActiveBusiness)
                                            · IČO {{ $navigationActiveBusiness->registration_number }}
                                        @endif
                                    </span>
                                </span>
                                <span aria-hidden="true">⌄</span>
                            </button>

                            <div x-cloak x-show="open" class="absolute right-0 z-20 mt-2 w-80 rounded-xl border border-slate-200 bg-white p-2 shadow-xl">
                                @foreach ($navigationBusinesses as $business)
                                    @php
                                        $switchColor = \App\Enums\BusinessThemeColor::tryFrom((string) $business->theme_color) ?? \App\Enums\BusinessThemeColor::Blue;
                                        $switchTheme = $switchColor->theme();
                                    @endphp
                                    <form method="POST" action="{{ route('business.switch', $business->uuid) }}">
                                        @csrf
                                        <button class="flex w-full items-start gap-3 rounded-lg px-3 py-3 text-left hover:bg-slate-50" type="submit">
                                            <span class="mt-1 h-3 w-3 shrink-0 rounded-full" style="background-color: {{ $switchTheme['accent'] }}" aria-hidden="true"></span>
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
                    @foreach ($navigationItems as $item)
                        <a href="{{ route($item['route']) }}"
                           class="block rounded-lg px-3 py-2 text-sm font-medium {{ request()->routeIs($item['active']) ? 'bg-blue-50 text-blue-800' : 'text-slate-700 hover:bg-slate-100' }}">
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
                    @if($navigationBusinesses->isNotEmpty())
                        <div class="mb-5 border-b border-slate-200 pb-5">
                            @if($navigationActiveBusiness)
                                <div class="mb-4 flex items-center gap-3 rounded-xl border px-3 py-3" data-mobile-menu-active-business data-active-business-name="{{ $navigationActiveBusiness->display_name }}" style="background-color: {{ $businessTheme['background'] }}; border-color: {{ $businessTheme['border'] }}; color: {{ $businessTheme['text'] }}">
                                    <span class="h-3 w-3 shrink-0 rounded-full" style="background-color: {{ $businessTheme['accent'] }}" aria-hidden="true"></span>
                                    <span class="min-w-0"><span class="block text-[11px] font-semibold uppercase tracking-wide">Aktivní subjekt</span><span class="block truncate font-bold">{{ $navigationActiveBusiness->display_name }}</span></span>
                                </div>
                            @endif
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Přepnout subjekt</p>
                            @foreach($navigationBusinesses as $business)
                                @php
                                    $mobileColor = \App\Enums\BusinessThemeColor::tryFrom((string) $business->theme_color) ?? \App\Enums\BusinessThemeColor::Blue;
                                    $mobileTheme = $mobileColor->theme();
                                @endphp
                                <form method="POST" action="{{ route('business.switch', $business->uuid) }}">
                                    @csrf
                                    <button type="submit" class="mb-2 flex w-full items-center gap-3 rounded-lg border px-3 py-2 text-left" style="{{ $navigationActiveBusiness?->is($business) ? 'background-color: '.$mobileTheme['background'].'; border-color: '.$mobileTheme['border'].'; color: '.$mobileTheme['text'] : 'border-color: #e2e8f0' }}">
                                        <span class="h-3 w-3 shrink-0 rounded-full" style="background-color: {{ $mobileTheme['accent'] }}" aria-hidden="true"></span>
                                        <span class="min-w-0 flex-1 truncate font-semibold">{{ $business->display_name }}</span>
                                        @if($navigationActiveBusiness?->is($business))<span class="text-xs font-bold">Aktivní</span>@endif
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                    <nav class="space-y-1">
                        @foreach ($navigationItems as $item)
                            <a href="{{ route($item['route']) }}"
                               class="block rounded-lg px-3 py-2 {{ request()->routeIs($item['active']) ? 'bg-blue-50 text-blue-800' : '' }}">
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </nav>
                    @auth
                        <div class="mt-5 border-t border-slate-200 pt-4" data-login-user-mobile-menu>
                            <span class="block text-xs font-medium text-slate-500">Přihlášen:</span>
                            <span class="mt-1 block font-semibold text-slate-800">{{ auth()->user()->name }}</span>
                        </div>
                    @endauth
                    <form method="POST" action="{{ route('logout') }}" class="mt-5">
                        @csrf
                        <button type="submit" class="button-secondary w-full">Odhlásit</button>
                    </form>
                </div>
            </div>

            <main class="min-w-0 flex-1">
                @if (session('status') || session('error'))
                    <div
                        class="mb-5 rounded-lg p-3 text-sm {{ session('error') ? 'border border-red-200 bg-red-50 text-red-800' : 'bg-emerald-50 text-emerald-800' }}"
                        role="{{ session('error') ? 'alert' : 'status' }}"
                    >
                        {{ session('error') ?? session('status') }}
                    </div>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
