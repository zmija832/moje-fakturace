@props(['invoice', 'revision', 'paymentSummary' => null, 'issueAvailability' => ['can_issue' => false, 'reason' => null], 'documentSequences' => null, 'sequencePreviews' => [], 'defaultSequenceUuid' => null, 'issueCorrelationUuid' => null, 'generationCorrelationUuid' => null, 'cancellationCorrelationUuid' => null])

@php
    $isDraft = $invoice->status === \App\Enums\InvoiceStatus::Draft;
    $isCancelled = $invoice->status === \App\Enums\InvoiceStatus::Cancelled;
    $sequences = $documentSequences ?? collect();
    $hasOutstanding = $paymentSummary !== null
        && \App\Domain\Invoices\InvoiceDecimal::compare($paymentSummary->remainingTotal, '0') > 0;
@endphp

<div x-data="{ cancelOpen: false, deleteOpen: false }">
<div class="flex flex-wrap items-start gap-2" aria-label="Akce faktury">
    @can('restore', $invoice)
        <form method="POST" action="{{ route('invoices.restore', $invoice->uuid) }}">@csrf @method('PATCH')<button class="button-primary" type="submit">Obnovit</button></form>
    @endcan
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
        @can('deletePermanently', $invoice)
            <button class="button-secondary border-red-300 text-red-800" type="button" @click="deleteOpen = true">Odstranit koncept</button>
        @endcan
        <a class="button-secondary" href="#invoice-audit-history">Auditní historie</a>
    @else
        @can('reviseIssued', $invoice)
            <a class="button-secondary border-red-300 text-red-800" href="{{ route('invoices.issued-edit.warning', $invoice->uuid) }}">Upravit vystavenou</a>
        @endcan

        @can('sendEmail', $invoice)
            <a class="button-primary" href="{{ route('invoices.email.form', $invoice->uuid) }}">Odeslat klientovi</a>
        @endcan

        @can('recordPayment', $invoice)
            @if($hasOutstanding)
                <a class="button-secondary" href="#invoice-payment-entry">Zaznamenat úhradu</a>
                <a class="button-secondary" href="{{ route('invoices.reminders.form', $invoice->uuid) }}">Odeslat upomínku</a>
            @endif
            <form method="POST" action="{{ route('invoices.reminders.toggle', $invoice->uuid) }}">@csrf @method('PATCH')
                <input type="hidden" name="disabled" value="{{ $invoice->reminderOverride?->disabled ? '0' : '1' }}">
                <button class="button-secondary" type="submit">{{ $invoice->reminderOverride?->disabled ? 'Zapnout automatické upomínky' : 'Nevyžadovat automatické upomínky' }}</button>
            </form>
        @endcan

        @can('print', $invoice)
            <a class="button-secondary" target="_blank" rel="noopener" href="{{ route('invoices.print', $invoice->uuid) }}">Tiskový náhled</a>
        @endcan

        @can('downloadPdf', $invoice)
            @if($invoice->currentPdfDocument() !== null)
                <a class="button-secondary" href="{{ route('invoices.pdf.download', $invoice->uuid) }}">Stáhnout PDF</a>
            @endif
        @endcan

        @can('generatePdf', $invoice)
            <form method="POST" action="{{ route('invoices.pdf.generate', $invoice->uuid) }}">
                @csrf
                <input type="hidden" name="generation_correlation_uuid" value="{{ $generationCorrelationUuid }}">
                <input type="hidden" name="force_regenerate" value="{{ $invoice->currentPdfDocument() !== null ? '1' : '0' }}">
                <button class="button-secondary" type="submit" title="Vytvoří nový neměnný PDF soubor ze stejné vystavené revize.">{{ $invoice->currentPdfDocument() === null ? 'Vygenerovat PDF' : 'Přegenerovat PDF' }}</button>
            </form>
        @endcan

        @can('duplicate', $invoice)
            <form method="POST" action="{{ route('invoices.duplicate', $invoice->uuid) }}">
                @csrf
                <button class="button-secondary" type="submit">Duplikovat fakturu</button>
            </form>
        @endcan

        @if(!$isCancelled)
            @can('cancel', $invoice)
                <button class="button-secondary border-red-300 text-red-800" type="button" @click="cancelOpen = true">Stornovat fakturu</button>
            @endcan
        @endif

        @can('managePublicLink', $invoice)
            <a class="button-secondary" href="#invoice-public-link">Webfaktura</a>
        @endcan

        @can('archive', $invoice)
            <form method="POST" action="{{ route('invoices.archive', $invoice->uuid) }}" onsubmit="return confirm('Vystavená faktura se pouze skryje ze seznamu; číslo, revize, PDF i audit zůstanou zachované. Pokračovat?')">
                @csrf @method('PATCH')
                <button class="button-secondary" type="submit">Archivovat ze seznamu</button>
            </form>
        @endcan

        <a class="button-secondary" href="#invoice-delivery-history">Historie odeslání</a>
        @can('viewAny', \App\Models\Business\Client::class)
            <a class="button-secondary" href="{{ route('clients.show', $revision->customerSnapshot->source_client_uuid) }}">Detail odběratele</a>
        @endcan
        <a class="button-secondary" href="#invoice-audit-history">Auditní historie</a>

        @can('deletePermanently', $invoice)
            <button class="button-secondary border-red-500 bg-red-50 font-semibold text-red-900" type="button" @click="deleteOpen = true">Smazat fakturu</button>
        @endcan
    @endif
</div>

@if(!$isDraft && !$isCancelled)
@can('cancel', $invoice)
<div x-cloak x-show="cancelOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="cancel-invoice-title" @keydown.escape.window="cancelOpen = false">
    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl" @click.outside="cancelOpen = false">
        <h2 id="cancel-invoice-title" class="text-xl font-bold">Stornovat fakturu {{ $invoice->document_number }}</h2>
        <p class="mt-2 text-sm text-slate-700">Faktura zůstane v evidenci a její číslo nebude možné znovu použít. Aktivní Webfaktura bude odvolána.</p>
        <form class="mt-5" method="POST" action="{{ route('invoices.cancel', $invoice->uuid) }}">@csrf
            <input type="hidden" name="expected_version" value="{{ $invoice->version }}">
            <input type="hidden" name="correlation_uuid" value="{{ $cancellationCorrelationUuid }}">
            <label for="cancellation_reason">Důvod storna *</label>
            <textarea id="cancellation_reason" name="reason" maxlength="255" required placeholder="Faktura byla vystavena omylem."></textarea>
            <div class="mt-5 flex justify-end gap-2"><button class="button-secondary" type="button" @click="cancelOpen = false">Zrušit</button><button class="button-primary bg-red-700 hover:bg-red-800" type="submit">Stornovat fakturu</button></div>
        </form>
    </div>
</div>
@endcan
@endif

@can('deletePermanently', $invoice)
<div x-cloak x-show="deleteOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="delete-invoice-title" @keydown.escape.window="deleteOpen = false">
    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl" @click.outside="deleteOpen = false">
        <h2 id="delete-invoice-title" class="text-xl font-bold">{{ $isDraft ? 'Smazat koncept?' : 'Smazat fakturu '.$invoice->document_number.'?' }}</h2>
        <p class="mt-2 text-sm text-slate-700">Faktura a její související data budou trvale odstraněna. Tuto operaci nelze vrátit.</p>
        <form class="mt-5 flex justify-end gap-2" method="POST" action="{{ route('invoices.delete', $invoice->uuid) }}">@csrf @method('DELETE')<input type="hidden" name="confirmation" value="1"><button class="button-secondary" type="button" @click="deleteOpen = false">Zrušit</button><button class="button-primary bg-red-700 hover:bg-red-800" type="submit">{{ $isDraft ? 'Odstranit koncept' : 'Smazat fakturu' }}</button></form>
    </div>
</div>
@endcan
</div>
