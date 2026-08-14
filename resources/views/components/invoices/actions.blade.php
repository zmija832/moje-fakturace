@props(['invoice', 'revision', 'paymentSummary' => null, 'issueAvailability' => ['can_issue' => false, 'reason' => null], 'documentSequences' => null, 'sequencePreviews' => [], 'defaultSequenceUuid' => null, 'issueCorrelationUuid' => null, 'generationCorrelationUuid' => null])

@php
    $isDraft = $invoice->status === \App\Enums\InvoiceStatus::Draft;
    $sequences = $documentSequences ?? collect();
    $hasOutstanding = $paymentSummary !== null
        && \App\Domain\Invoices\InvoiceDecimal::compare($paymentSummary->remainingTotal, '0') > 0;
@endphp

<div class="relative flex flex-wrap items-start gap-2">
    @if($isDraft)
        @can('update', $invoice)
            <a class="button-secondary" href="{{ route('invoices.edit', $invoice->uuid) }}">Upravit návrh</a>
        @endcan
        @can('issue', $invoice)
            @if($issueAvailability['can_issue'])
                <details class="relative">
                    <summary class="button-primary cursor-pointer list-none">Vystavit fakturu</summary>
                    <div class="absolute left-0 z-20 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-xl border border-slate-200 bg-white p-4 shadow-xl">
                        <p class="text-sm text-slate-700">Vystavením se aktuální revize uzamkne a přidělené číslo dokladu nebude možné vrátit.</p>
                        <form method="POST" action="{{ route('invoices.issue', $invoice->uuid) }}" class="mt-4 space-y-3">
                            @csrf
                            <input type="hidden" name="expected_version" value="{{ $invoice->version }}">
                            <input type="hidden" name="correlation_uuid" value="{{ $issueCorrelationUuid }}">
                            <div>
                                <label for="document_sequence_uuid">Číselná řada</label>
                                <select id="document_sequence_uuid" name="document_sequence_uuid" @required($defaultSequenceUuid === null)>
                                    <option value="">{{ $defaultSequenceUuid === null ? 'Vyberte číselnou řadu' : 'Použít výchozí řadu' }}</option>
                                    @foreach($sequences as $sequence)
                                        <option value="{{ $sequence->uuid }}">{{ $sequence->name }} · {{ $sequencePreviews[$sequence->uuid] }}{{ $defaultSequenceUuid === $sequence->uuid ? ' · výchozí' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button class="button-primary w-full" type="submit">Potvrdit vystavení</button>
                        </form>
                    </div>
                </details>
            @else
                <div>
                    <button class="button-primary cursor-not-allowed opacity-50" type="button" disabled>Vystavit fakturu</button>
                    <p class="mt-1 max-w-md text-sm text-amber-800">{{ $issueAvailability['reason'] }}</p>
                    @can('updateAny', \App\Models\Business\DocumentSequence::class)
                        @if($sequences->isEmpty())
                            <a class="text-sm font-semibold text-blue-700 underline" href="{{ route('document-sequences.index') }}">Nastavit číselnou řadu</a>
                        @endif
                    @endcan
                </div>
            @endif
        @endcan
    @else
        @can('sendEmail', $invoice)
            <a class="button-primary" href="{{ route('invoices.email.form', $invoice->uuid) }}">Odeslat klientovi</a>
        @endcan
        @can('recordPayment', $invoice)
            @if($hasOutstanding)
                <a class="button-secondary" href="#invoice-payments">Zaznamenat úhradu</a>
            @endif
        @endcan
    @endif

    <details class="relative">
        <summary class="button-secondary cursor-pointer list-none">Další akce</summary>
        <div class="absolute right-0 z-20 mt-2 w-64 rounded-xl border border-slate-200 bg-white p-2 text-sm shadow-xl">
            @if(!$isDraft)
                @can('print', $invoice)
                    <a class="block rounded-lg px-3 py-2 hover:bg-slate-50" target="_blank" rel="noopener" href="{{ route('invoices.print', $invoice->uuid) }}">Tiskový náhled</a>
                @endcan
                @can('downloadPdf', $invoice)
                    @if($invoice->documents->isNotEmpty())
                        <a class="block rounded-lg px-3 py-2 hover:bg-slate-50" href="{{ route('invoices.pdf.download', $invoice->uuid) }}">Stáhnout PDF</a>
                    @endif
                @endcan
                @can('generatePdf', $invoice)
                    <form method="POST" action="{{ route('invoices.pdf.generate', $invoice->uuid) }}">@csrf<input type="hidden" name="generation_correlation_uuid" value="{{ $generationCorrelationUuid }}"><input type="hidden" name="force_regenerate" value="{{ $invoice->documents->isNotEmpty() ? '1' : '0' }}"><button class="block w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50" type="submit">{{ $invoice->documents->isEmpty() ? 'Vygenerovat PDF' : 'Vytvořit novou verzi PDF' }}</button></form>
                @endcan
                <a class="block rounded-lg px-3 py-2 hover:bg-slate-50" href="#invoice-delivery-history">Historie odeslání</a>
                @can('viewAny', \App\Models\Business\Client::class)
                    <a class="block rounded-lg px-3 py-2 hover:bg-slate-50" href="{{ route('clients.show', $revision->customerSnapshot->source_client_uuid) }}">Detail odběratele</a>
                @endcan
                <div class="my-1 border-t border-slate-200"></div>
            @endif
            <a class="block rounded-lg px-3 py-2 hover:bg-slate-50" href="#invoice-audit-history">Auditní historie</a>
        </div>
    </details>
</div>
