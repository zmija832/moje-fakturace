<?php

namespace App\Http\Controllers;

use App\Domain\Invoices\Exceptions\InvoiceDraftIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceDraftVersionConflict;
use App\Domain\Invoices\Exceptions\InvoiceIssuedImmutable;
use App\Domain\Invoices\Exceptions\InvoiceIssuedRevisionIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceIssuedRevisionVersionConflict;
use App\Domain\Invoices\Exceptions\InvoiceIssueIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceIssueSequenceUnavailable;
use App\Domain\Invoices\Exceptions\InvoiceIssueVersionConflict;
use App\Domain\Invoices\Exceptions\InvoiceNotDraft;
use App\Domain\Invoices\Exceptions\InvoiceNotReadyForIssue;
use App\Domain\Invoices\Exceptions\InvoicePdfGenerationFailed;
use App\Enums\BusinessAuditableType;
use App\Enums\DefaultPaymentMethod;
use App\Enums\InvoiceStatus;
use App\Http\Requests\InvoiceIndexRequest;
use App\Http\Requests\IssueInvoiceRequest;
use App\Http\Requests\PreviewInvoiceRequest;
use App\Http\Requests\StoreInvoiceDraftRequest;
use App\Http\Requests\UpdateInvoiceDraftRequest;
use App\Http\Requests\UpdateIssuedInvoiceRequest;
use App\Models\Business\Invoice;
use App\Services\Business\BusinessAuditService;
use App\Services\Business\InvoiceArchiveService;
use App\Services\Business\InvoiceDraftEditor;
use App\Services\Business\InvoiceDraftService;
use App\Services\Business\InvoiceDuplicator;
use App\Services\Business\InvoiceFormOptions;
use App\Services\Business\InvoiceIssueAvailability;
use App\Services\Business\InvoiceIssuedRevisionService;
use App\Services\Business\InvoiceIssuer;
use App\Services\Business\InvoicePaymentReader;
use App\Services\Business\InvoicePdfGenerator;
use App\Services\Business\InvoicePreviewService;
use App\Services\Business\InvoicePublicLinkService;
use App\Services\Business\InvoiceQrPaymentService;
use App\Services\Business\InvoiceReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(InvoiceIndexRequest $request, InvoiceReader $reader, InvoiceFormOptions $options): View
    {
        $filters = $request->validated();

        return view('business.invoices.index', [
            'invoices' => $reader->search($filters),
            'filters' => $filters,
            'clients' => $options->clientsForIndex(),
        ]);
    }

    public function create(InvoiceFormOptions $options): View
    {
        Gate::authorize('create', Invoice::class);
        $defaults = $options->defaults();

        return view('business.invoices.create', [
            ...$options->forDate($defaults['taxable_supply_on']),
            'defaults' => $defaults,
        ]);
    }

    public function store(
        StoreInvoiceDraftRequest $request,
        InvoiceDraftService $service,
        InvoiceIssuer $issuer,
        InvoiceIssueAvailability $availability,
        InvoicePdfGenerator $pdfGenerator,
    ): RedirectResponse {
        $attributes = $request->safe()->except(['submission_action']);
        $invoice = $service->create($attributes);

        if ($request->validated('submission_action') === 'issue') {
            Gate::authorize('issue', $invoice);

            try {
                $issued = $issuer->issue($invoice->uuid, $invoice->version, (string) Str::uuid());

                $pdfError = $this->generatePdfAfterIssuance($issued, $pdfGenerator);
                $response = redirect()->route('invoices.show', $issued->uuid)
                    ->with('status', 'Faktura byla vytvořena a vystavena pod číslem '.$issued->document_number.'.');

                return $pdfError === null ? $response : $response->with('error', $pdfError);
            } catch (InvoiceNotReadyForIssue $exception) {
                return redirect()->route('invoices.show', $invoice->uuid)
                    ->with('error', 'Faktura byla uložena jako koncept, ale nebyla vystavena: '.$availability->readinessReason($exception->reason));
            } catch (InvoiceIssueSequenceUnavailable) {
                return redirect()->route('invoices.show', $invoice->uuid)
                    ->with('error', 'Faktura byla uložena jako koncept, ale nebyla vystavena: není nastavena dostupná výchozí číselná řada pro vydané faktury.');
            } catch (InvoiceIssueVersionConflict|InvoiceIssueIdempotencyConflict) {
                return redirect()->route('invoices.show', $invoice->uuid)
                    ->with('error', 'Faktura byla uložena jako koncept, ale vystavení se nepodařilo bezpečně dokončit. Zkuste ji vystavit z detailu konceptu.');
            }
        }

        return redirect()->route('invoices.show', $invoice->uuid)
            ->with('status', 'Koncept faktury byl uložen.');
    }

    public function show(
        string $uuid,
        InvoiceReader $reader,
        InvoiceFormOptions $options,
        BusinessAuditService $audit,
        InvoicePaymentReader $paymentReader,
        InvoiceIssueAvailability $availability,
        InvoicePublicLinkService $publicLinks,
        InvoiceQrPaymentService $qrPayments,
    ): View {
        Gate::authorize('view', Invoice::class);
        $invoice = $reader->find($uuid);
        $revision = $invoice->status->hasIssuedDocument() ? $invoice->issuedRevision : $invoice->currentRevision;
        $issueOptions = $invoice->status === InvoiceStatus::Draft
            ? $options->forDate($revision->issued_on->format('Y-m-d')) : [];
        $paymentSummary = $invoice->status->hasIssuedDocument() ? $paymentReader->summary($invoice) : null;
        $issueAvailability = $invoice->status === InvoiceStatus::Draft && auth()->user()?->can('issue', $invoice)
            ? $availability->for($invoice)
            : ['can_issue' => false, 'reason' => null];
        $publicLink = $invoice->status === InvoiceStatus::Issued && auth()->user()?->can('managePublicLink', $invoice)
            ? $publicLinks->activeForInvoice($invoice)
            : null;

        return view('business.invoices.show', [
            'invoice' => $invoice,
            'revision' => $revision,
            'audits' => $audit->forEntity(BusinessAuditableType::Invoice, $invoice->uuid, 30),
            'issueCorrelationUuid' => (string) Str::uuid(),
            'generationCorrelationUuid' => (string) Str::uuid(),
            'paymentCorrelationUuid' => (string) Str::uuid(),
            'cancellationCorrelationUuid' => (string) Str::uuid(),
            'paymentSummary' => $paymentSummary,
            'paymentMethods' => DefaultPaymentMethod::options(),
            'issueAvailability' => $issueAvailability,
            'publicLink' => $publicLink,
            'publicLinkUrl' => $publicLink ? $publicLinks->url($publicLink) : null,
            'qrPayment' => $invoice->status === InvoiceStatus::Issued ? $qrPayments->create($invoice, $revision) : null,
            ...$issueOptions,
        ]);
    }

    public function edit(string $uuid, InvoiceReader $reader, InvoiceFormOptions $options): View
    {
        $invoice = $reader->find($uuid);
        Gate::authorize('update', $invoice);
        $revision = $invoice->currentRevision;

        return view('business.invoices.edit', [
            'invoice' => $invoice,
            'revision' => $revision,
            'correlationUuid' => (string) Str::uuid(),
            ...$options->forDate($revision->taxable_supply_on->format('Y-m-d')),
        ]);
    }

    public function duplicate(string $uuid, InvoiceReader $reader, InvoiceDuplicator $duplicator): RedirectResponse
    {
        $source = $reader->find($uuid);
        Gate::authorize('duplicate', $source);

        try {
            $draft = $duplicator->duplicate($source);
        } catch (ValidationException) {
            return redirect()->route('invoices.show', $source->uuid)
                ->with('error', 'Fakturu nelze bezpečně duplikovat. Ověřte dostupnost odběratele, bankovního účtu a sazeb DPH.');
        }

        return redirect()->route('invoices.edit', $draft->uuid)
            ->with('status', 'Byl vytvořen nový koncept podle původní faktury. Před uložením zkontrolujte data a částky.');
    }

    public function issuedEditWarning(string $uuid, InvoiceReader $reader): View
    {
        $invoice = $reader->find($uuid);
        Gate::authorize('reviseIssued', $invoice);

        return view('business.invoices.issued-edit-warning', ['invoice' => $invoice]);
    }

    public function confirmIssuedEdit(string $uuid, InvoiceReader $reader): RedirectResponse
    {
        $invoice = $reader->find($uuid);
        Gate::authorize('reviseIssued', $invoice);
        session()->put($this->issuedEditSessionKey($invoice->uuid), now()->addMinutes(30)->timestamp);

        return redirect()->route('invoices.issued-edit', $invoice->uuid);
    }

    public function issuedEdit(string $uuid, InvoiceReader $reader, InvoiceFormOptions $options): View|RedirectResponse
    {
        $invoice = $reader->find($uuid);
        Gate::authorize('reviseIssued', $invoice);
        if (! $this->issuedEditConfirmed($invoice->uuid)) {
            return redirect()->route('invoices.issued-edit.warning', $invoice->uuid);
        }
        $revision = $invoice->issuedRevision;

        return view('business.invoices.issued-edit', [
            'invoice' => $invoice,
            'revision' => $revision,
            'correlationUuid' => (string) Str::uuid(),
            ...$options->forDate($revision->taxable_supply_on->format('Y-m-d')),
        ]);
    }

    public function updateIssued(
        UpdateIssuedInvoiceRequest $request,
        string $uuid,
        InvoiceReader $reader,
        InvoiceIssuedRevisionService $editor,
        InvoicePdfGenerator $pdfGenerator,
    ): RedirectResponse {
        $invoice = $reader->find($uuid);
        Gate::authorize('reviseIssued', $invoice);
        if (! $this->issuedEditConfirmed($invoice->uuid)) {
            return redirect()->route('invoices.issued-edit.warning', $invoice->uuid)
                ->with('error', 'Před admin úpravou je nutné potvrdit varování.');
        }
        $originalRevisionId = $invoice->issued_revision_id;

        try {
            $revision = $editor->update(
                $invoice->uuid,
                (int) $request->validated('version'),
                (string) $request->validated('correlation_uuid'),
                $request->safe()->except(['version', 'correlation_uuid', 'admin_edit_confirmation']),
            );
        } catch (InvoiceIssuedRevisionVersionConflict) {
            return redirect()->route('invoices.show', $invoice->uuid)
                ->with('error', 'Vystavenou fakturu mezitím upravil jiný administrátor. Data nebyla přepsána.');
        } catch (InvoiceIssuedRevisionIdempotencyConflict) {
            return redirect()->route('invoices.show', $invoice->uuid)
                ->with('error', 'Opakovaný požadavek nelze bezpečně přiřadit k této faktuře.');
        }

        session()->forget($this->issuedEditSessionKey($invoice->uuid));
        if ((int) $revision->id === (int) $originalRevisionId) {
            return redirect()->route('invoices.show', $invoice->uuid)->with('status', 'Nebyly zjištěny žádné změny.');
        }

        try {
            $pdfGenerator->generate($invoice->uuid, (string) Str::uuid(), true);
        } catch (InvoicePdfGenerationFailed) {
            return redirect()->route('invoices.show', $invoice->uuid)
                ->with('status', 'Nová issued revize byla bezpečně uložena; původní revize zůstala zachována.')
                ->with('error', 'Nové PDF se nepodařilo vytvořit. Faktura zůstává vystavená; použijte akci Přegenerovat PDF.');
        }

        return redirect()->route('invoices.show', $invoice->uuid)
            ->with('status', 'Vystavená faktura byla uložena jako nová immutable revize a vznikla nová verze PDF.');
    }

    public function archive(
        string $uuid,
        InvoiceReader $reader,
        InvoiceArchiveService $archive,
    ): RedirectResponse {
        $invoice = $reader->find($uuid);
        Gate::authorize('archive', $invoice);

        try {
            $archive->archive($invoice->uuid);
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('invoices.index')
            ->with('status', 'Faktura byla archivována ze seznamu. Doklad, číslo, revize, PDF a audit zůstaly zachované.');
    }

    public function restore(
        string $uuid,
        InvoiceReader $reader,
        InvoiceArchiveService $archive,
    ): RedirectResponse {
        $invoice = $reader->find($uuid);
        Gate::authorize('restore', $invoice);

        try {
            $archive->restore($invoice->uuid);
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('invoices.show', $invoice->uuid)->with('status', 'Faktura byla obnovena do aktivního seznamu.');
    }

    public function update(
        UpdateInvoiceDraftRequest $request,
        string $uuid,
        InvoiceReader $reader,
        InvoiceDraftEditor $editor,
    ): RedirectResponse {
        $invoice = $reader->find($uuid);
        Gate::authorize('update', $invoice);
        $originalRevisionId = $invoice->current_revision_id;

        try {
            $revision = $editor->update(
                $invoice->uuid,
                (int) $request->validated('version'),
                (string) $request->validated('correlation_uuid'),
                $request->safe()->except(['version', 'correlation_uuid']),
            );
        } catch (InvoiceDraftVersionConflict) {
            return redirect()->route('invoices.edit', $invoice->uuid)
                ->with('error', 'Návrh mezitím změnil jiný uživatel. Načtěte aktuální verzi; data nebyla přepsána.');
        } catch (InvoiceNotDraft|InvoiceIssuedImmutable) {
            return redirect()->route('invoices.show', $invoice->uuid)
                ->with('error', 'Vystavenou fakturu nelze upravovat.');
        } catch (InvoiceDraftIdempotencyConflict) {
            return redirect()->route('invoices.show', $invoice->uuid)
                ->with('error', 'Opakovaný požadavek nelze bezpečně přiřadit k této faktuře.');
        }

        return redirect()->route('invoices.show', $invoice->uuid)->with(
            'status',
            (int) $revision->id === (int) $originalRevisionId
                ? 'Nebyly zjištěny žádné změny.' : 'Návrh faktury byl aktualizován.',
        );
    }

    public function issue(
        IssueInvoiceRequest $request,
        string $uuid,
        InvoiceReader $reader,
        InvoiceIssuer $issuer,
        InvoiceIssueAvailability $availability,
        InvoicePdfGenerator $pdfGenerator,
    ): RedirectResponse {
        $invoice = $reader->find($uuid);
        Gate::authorize('issue', $invoice);

        try {
            $issued = $issuer->issue(
                $invoice->uuid,
                (int) $request->validated('expected_version'),
                (string) $request->validated('correlation_uuid'),
                $request->validated('document_sequence_uuid'),
            );
        } catch (InvoiceNotReadyForIssue $exception) {
            return back()->with('error', 'Fakturu nelze vystavit: '.$availability->readinessReason($exception->reason));
        } catch (InvoiceIssueSequenceUnavailable) {
            return back()->with('error', 'Fakturu nelze vystavit: vybraná nebo výchozí číselná řada není dostupná.');
        } catch (InvoiceIssueVersionConflict) {
            return back()->with('error', 'Návrh mezitím změnil jiný uživatel. Načtěte aktuální verzi.');
        } catch (InvoiceIssueIdempotencyConflict) {
            return back()->with('error', 'Požadavek na vystavení má konfliktní identifikátor. Faktura nebyla změněna.');
        } catch (InvoiceNotDraft|InvoiceIssuedImmutable) {
            return redirect()->route('invoices.show', $invoice->uuid)
                ->with('error', 'Vystavenou fakturu nelze vystavit znovu jiným požadavkem.');
        }

        $pdfError = $this->generatePdfAfterIssuance($issued, $pdfGenerator);
        $response = redirect()->route('invoices.show', $issued->uuid)
            ->with('status', 'Faktura byla vystavena pod číslem '.$issued->document_number.'.');

        return $pdfError === null ? $response : $response->with('error', $pdfError);
    }

    private function generatePdfAfterIssuance(Invoice $invoice, InvoicePdfGenerator $generator): ?string
    {
        try {
            $generator->generate($invoice->uuid, (string) Str::uuid());

            return null;
        } catch (InvoicePdfGenerationFailed) {
            return 'Faktura zůstala úspěšně vystavená, ale první PDF se nepodařilo vytvořit. Použijte akci Přegenerovat PDF.';
        }
    }

    private function issuedEditSessionKey(string $invoiceUuid): string
    {
        return 'invoice-issued-edit-confirmed.'.$invoiceUuid;
    }

    private function issuedEditConfirmed(string $invoiceUuid): bool
    {
        $expiresAt = session()->get($this->issuedEditSessionKey($invoiceUuid));

        return is_int($expiresAt) && $expiresAt >= now()->timestamp;
    }

    public function preview(PreviewInvoiceRequest $request, InvoicePreviewService $preview): JsonResponse
    {
        return response()->json($preview->calculate($request->validated()));
    }
}
