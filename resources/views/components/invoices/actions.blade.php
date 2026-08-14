@props(['invoice', 'revision', 'paymentSummary' => null, 'issueAvailability' => ['can_issue' => false, 'reason' => null], 'documentSequences' => null, 'sequencePreviews' => [], 'defaultSequenceUuid' => null, 'issueCorrelationUuid' => null, 'generationCorrelationUuid' => null])

@php
    $isDraft = $invoice->status === \App\Enums\InvoiceStatus::Draft;
    $sequences = $documentSequences ?? collect();
    $hasOutstanding = $paymentSummary !== null
        && \App\Domain\Invoices\InvoiceDecimal::compare($paymentSummary->remainingTotal, '0') > 0;
@endphp

<div class="flex flex-wrap items-start gap-2" aria-label="Akce faktury">
    @if($isDraft)
        @can('update', $invoice)
            <a class="button-secondary" href="{{ route('invoices.edit', $invoice->uuid) }}">Upravit návrh</a>
        @endcan

        @can('issue', $invoice)
            @if($issueAvailability['can_issue'])
                <div>
                    <form method="POST" action="{{ route('invoices.issue', $invoice->uuid) }}" class="flex flex-wrap items-end gap-2" onsubmit="return confirm('Vystavením se aktuální revize uzamkne. Opravdu pokračovat?')">
                        @csrf
                        <input type="hidden" name="expected_version" value="{{ $invoice->version }}">
                        <input type="hidden" name="correlation_uuid" value="{{ $issueCorrelationUuid }}">
                        <div>
                            <label class="sr-only" for="document_sequence_uuid">Číselná řada</label>
                            <select id="document_sequence_uuid" name="document_sequence_uuid" @required($defaultSequenceUuid === null)>
                                <option value="">{{ $defaultSequenceUuid === null ? 'Vyberte číselnou řadu' : 'Použít výchozí řadu' }}</option>
                                @foreach($sequences as $sequence)
                                    <option value="{{ $sequence->uuid }}">{{ $sequence->name }} · {{ $sequencePreviews[$sequence->uuid] }}{{ $defaultSequenceUuid === $sequence->uuid ? ' · výchozí' : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button class="button-primary" type="submit">Vystavit fakturu</button>
                    </form>
                    <p class="mt-1 text-xs text-slate-600">Vystavením se aktuální revize uzamkne.</p>
                </div>
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

        @can('archive', $invoice)
            <form method="POST" action="{{ route('invoices.archive', $invoice->uuid) }}" onsubmit="return confirm('Koncept se skryje z běžného seznamu. Revize a audit zůstanou zachované. Pokračovat?')">
                @csrf
                @method('PATCH')
                <button class="button-secondary text-red-700" type="submit">Archivovat koncept</button>
            </form>
        @endcan
        <a class="button-secondary" href="#invoice-audit-history">Auditní historie</a>
    @else
        @can('sendEmail', $invoice)
            <a class="button-primary" href="{{ route('invoices.email.form', $invoice->uuid) }}">Odeslat klientovi</a>
        @endcan

        @can('recordPayment', $invoice)
            @if($hasOutstanding)
                <a class="button-secondary" href="#invoice-payment-entry">Zaznamenat úhradu</a>
            @endif
        @endcan

        @can('print', $invoice)
            <a class="button-secondary" target="_blank" rel="noopener" href="{{ route('invoices.print', $invoice->uuid) }}">Tiskový náhled</a>
        @endcan

        @can('downloadPdf', $invoice)
            @if($invoice->documents->isNotEmpty())
                <a class="button-secondary" href="{{ route('invoices.pdf.download', $invoice->uuid) }}">Stáhnout PDF</a>
            @endif
        @endcan

        @can('generatePdf', $invoice)
            <form method="POST" action="{{ route('invoices.pdf.generate', $invoice->uuid) }}">
                @csrf
                <input type="hidden" name="generation_correlation_uuid" value="{{ $generationCorrelationUuid }}">
                <input type="hidden" name="force_regenerate" value="{{ $invoice->documents->isNotEmpty() ? '1' : '0' }}">
                <button class="button-secondary" type="submit" title="Vytvoří nový neměnný PDF soubor ze stejné vystavené revize.">{{ $invoice->documents->isEmpty() ? 'Vygenerovat PDF' : 'Přegenerovat PDF' }}</button>
            </form>
        @endcan

        @can('create', \App\Models\Business\Invoice::class)
            <form method="POST" action="{{ route('invoices.duplicate', $invoice->uuid) }}">
                @csrf
                <button class="button-secondary" type="submit">Duplikovat fakturu</button>
            </form>
        @endcan

        @can('managePublicLink', $invoice)
            <a class="button-secondary" href="#invoice-public-link">Webfaktura</a>
        @endcan

        <a class="button-secondary" href="#invoice-delivery-history">Historie odeslání</a>
        @can('viewAny', \App\Models\Business\Client::class)
            <a class="button-secondary" href="{{ route('clients.show', $revision->customerSnapshot->source_client_uuid) }}">Detail odběratele</a>
        @endcan
        <a class="button-secondary" href="#invoice-audit-history">Auditní historie</a>
    @endif
</div>
