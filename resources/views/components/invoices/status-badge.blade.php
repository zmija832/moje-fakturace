@props(['invoice', 'paymentSummary' => null])

@php
    if ($invoice->status === \App\Enums\InvoiceStatus::Draft) {
        $label = 'Koncept';
        $classes = 'bg-slate-100 text-slate-700 ring-slate-200';
    } else {
        $label = $paymentSummary?->status->label() ?? $invoice->status->label();
        $classes = match ($paymentSummary?->status) {
            \App\Enums\InvoicePaymentStatus::Unpaid => 'bg-amber-100 text-amber-900 ring-amber-200',
            \App\Enums\InvoicePaymentStatus::PartiallyPaid => 'bg-blue-100 text-blue-800 ring-blue-200',
            \App\Enums\InvoicePaymentStatus::Paid => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
            \App\Enums\InvoicePaymentStatus::Overpaid => 'bg-violet-100 text-violet-800 ring-violet-200',
            default => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }
@endphp

<span class="inline-flex flex-wrap items-center gap-1.5">
    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $classes }}">{{ $label }}</span>
    @if($invoice->status !== \App\Enums\InvoiceStatus::Draft && $paymentSummary?->isOverdue)
        <span class="inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-800 ring-1 ring-inset ring-red-200">Po splatnosti</span>
    @endif
</span>
