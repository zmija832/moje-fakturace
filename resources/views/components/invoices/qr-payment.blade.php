@props(['qr', 'title' => 'QR Platba'])

@if($qr?->available)
    <section class="card mt-6" aria-labelledby="invoice-qr-payment-title">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 id="invoice-qr-payment-title" class="text-lg font-bold">{{ $title }}</h2>
                <p class="mt-1 max-w-xl text-sm text-slate-600">Naskenujte v bankovní aplikaci. Údaje vycházejí z neměnného snapshotu vystavené faktury.</p>
            </div>
            <img class="h-40 w-40 shrink-0" src="{{ $qr->svgDataUri }}" alt="QR kód pro zaplacení faktury">
        </div>
    </section>
@endif