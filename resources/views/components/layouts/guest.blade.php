<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Přihlášení' }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="flex min-h-screen items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
            <div class="mb-6 text-center">
                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-blue-700 text-xl font-bold text-white" aria-hidden="true">F</div>
                <h1 class="text-2xl font-bold">{{ config('app.name') }}</h1>
                <p class="mt-1 text-sm text-slate-600">Soukromá fakturační administrace</p>
            </div>

            <section class="card">
                @if (session('status'))
                    <div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800" role="status">
                        {{ session('status') }}
                    </div>
                @endif

                {{ $slot }}
            </section>
        </div>
    </main>
</body>
</html>
