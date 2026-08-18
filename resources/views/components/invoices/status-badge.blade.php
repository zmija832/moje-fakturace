@props(['invoice', 'paymentSummary' => null])

@php
    $state = $invoice->displayState($paymentSummary);
    $label = $invoice->archived_at !== null && $invoice->status === \App\Enums\InvoiceStatus::Draft
        ? 'Archivovaný koncept' : $state->label();
    $classes = $state->badgeClasses();
    $showPaymentAndOverdue = $state === \App\Enums\InvoiceDisplayState::Overdue
        && $invoice->status === \App\Enums\InvoiceStatus::Issued;
    $paymentState = $showPaymentAndOverdue ? $invoice->paymentDisplayState($paymentSummary) : null;
@endphp

<span class="inline-flex flex-wrap items-center gap-1.5">
    @if($paymentState)
        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $paymentState->badgeClasses() }}">{{ $paymentState->label() }}</span>
    @endif
    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $classes }}">{{ $label }}</span>
</span>
