@props(['invoice'])
@php($viewedLink = $invoice->customerViewedPublicLink())
@if($viewedLink)
    <span
        class="inline-flex text-slate-500"
        title="Poprvé zobrazena: {{ $viewedLink->first_viewed_at->format('d. m. Y H:i') }}&#10;Naposledy: {{ $viewedLink->last_viewed_at->format('d. m. Y H:i') }}"
        aria-label="Webfaktura zobrazena zákazníkem"
    >
        <span aria-hidden="true">👁</span>
    </span>
@endif
