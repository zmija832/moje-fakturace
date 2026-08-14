<x-layouts.app :title="$invoice->document_number ?? 'Koncept faktury'">
    @php
        $isDraft = $invoice->status === \App\Enums\InvoiceStatus::Draft;
    @endphp

    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3"><p class="text-sm font-medium text-blue-700">Faktury</p><x-invoices.status-badge :invoice="$invoice" :payment-summary="$paymentSummary" /></div>
                <h1 class="mt-2 text-2xl font-bold">{{ $invoice->document_number ?? 'Koncept faktury' }}</h1>
                <p class="mt-2 text-slate-700">{{ $revision->customerSnapshot->display_name }}</p>
                <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm text-slate-600">
                    <span>{{ $isDraft ? 'Datum' : 'Vystavení' }} {{ $revision->issued_on->format('j. n. Y') }}</span>
                    @if(!$isDraft)<span>Splatnost {{ $revision->due_on->format('j. n. Y') }}</span>@endif
                    @if($isDraft)<span>Revize {{ $revision->revision_number }} · verze {{ $invoice->version }}</span>@endif
                </div>
            </div>
            <div class="shrink-0 text-left lg:text-right"><p class="text-sm text-slate-500">Celková částka</p><p class="mt-1 text-3xl font-bold tabular-nums">{{ \App\Domain\Invoices\InvoiceDecimal::format($revision->grand_total) }} {{ $revision->currency }}</p></div>
        </div>
        <div class="mt-5 border-t border-slate-200 pt-4">
            <x-invoices.actions :invoice="$invoice" :revision="$revision" :payment-summary="$paymentSummary" :issue-availability="$issueAvailability" :document-sequences="$documentSequences ?? collect()" :sequence-previews="$sequencePreviews ?? []" :default-sequence-uuid="$defaultSequenceUuid ?? null" :issue-correlation-uuid="$issueCorrelationUuid" :generation-correlation-uuid="$generationCorrelationUuid" />
        </div>
    </section>

    @if($isDraft)<div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Koncept zatím nemá číslo dokladu.</strong> Před vystavením jej lze upravit; každá skutečná změna vytvoří novou revizi.</div>@else<div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900"><strong>Vystavený doklad je neměnný.</strong> Jeho číslo, revizi, snapshoty ani částky nelze změnit.</div>@endif
    <x-invoices.detail :invoice="$invoice" :revision="$revision" />
    @if(!$isDraft)
        <x-invoices.payments :invoice="$invoice" :summary="$paymentSummary" :payment-methods="$paymentMethods" :correlation-uuid="$paymentCorrelationUuid" />
        <x-invoices.delivery-history :invoice="$invoice" />
    @endif
    <div id="invoice-audit-history" class="scroll-mt-6">
        <x-invoices.audit-history :audits="$audits" />
    </div>
    <div class="mt-6"><a class="button-secondary" href="{{ route('invoices.index') }}">Zpět na faktury</a></div>
</x-layouts.app>
