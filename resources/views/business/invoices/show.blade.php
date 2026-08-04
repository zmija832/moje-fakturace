<x-layouts.app :title="$invoice->document_number ?? 'Návrh faktury'">
    @php $isDraft=$invoice->status === \App\Enums\InvoiceStatus::Draft; @endphp
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:justify-between"><div><p class="text-sm font-medium text-blue-700">Faktura</p><h1 class="mt-1 text-2xl font-bold">{{ $invoice->document_number ?? 'Návrh bez čísla' }}</h1><p class="mt-2 text-sm text-slate-600">{{ $invoice->status->label() }} · revize {{ $revision->revision_number }} · verze {{ $invoice->version }}</p></div>@can('update',$invoice)<a class="button-primary" href="{{ route('invoices.edit',$invoice->uuid) }}">Upravit návrh</a>@endcan</div>
    @if($isDraft)<div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Návrh zatím nemá číslo dokladu.</strong> Před vystavením jej lze změnit; každá skutečná změna vytvoří novou revizi.</div>@else<div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900"><strong>Vystavený doklad je neměnný.</strong> Jeho číslo, revizi, snapshoty ani částky nelze změnit.</div>@endif
    <x-invoices.detail :invoice="$invoice" :revision="$revision" />
    @if(!$isDraft)
        <x-invoices.payments :invoice="$invoice" :summary="$paymentSummary" :payment-methods="$paymentMethods" :correlation-uuid="$paymentCorrelationUuid" />
    @endif
    @if($isDraft && auth()->user()->can('issue',$invoice))
        <x-invoices.issue-panel :invoice="$invoice" :document-sequences="$documentSequences" :sequence-previews="$sequencePreviews" :default-sequence-uuid="$defaultSequenceUuid" :correlation-uuid="$issueCorrelationUuid" />
    @elseif(!$isDraft)
        <x-invoices.delivery-history :invoice="$invoice" :generation-correlation-uuid="$generationCorrelationUuid" />
    @endif
    <x-invoices.audit-history :audits="$audits" />
    <div class="mt-6"><a class="button-secondary" href="{{ route('invoices.index') }}">Zpět na faktury</a></div>
</x-layouts.app>
