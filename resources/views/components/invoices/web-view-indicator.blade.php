@props(['invoice'])
@php($viewedLink = $invoice->customerViewedPublicLink())
@if($viewedLink)
    <svg
        data-web-invoice-viewed
        class="inline-block size-4 shrink-0 align-middle text-slate-400"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.75"
        stroke-linecap="round"
        stroke-linejoin="round"
        title="Webfaktura zobrazena&#10;Poprvé: {{ $viewedLink->first_viewed_at->format('j. n. Y H:i') }}&#10;Naposledy: {{ $viewedLink->last_viewed_at->format('j. n. Y H:i') }}"
        role="img"
        aria-label="Webfaktura zobrazena zákazníkem"
    >
        <path d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6S2.25 12 2.25 12Z" />
        <circle cx="12" cy="12" r="2.75" />
    </svg>
@endif
