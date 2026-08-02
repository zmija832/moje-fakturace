<?php

namespace App\Http\Controllers;

use App\Domain\Invoices\Exceptions\InvoiceDraftIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceDraftVersionConflict;
use App\Domain\Invoices\Exceptions\InvoiceIssuedImmutable;
use App\Domain\Invoices\Exceptions\InvoiceIssueIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceIssueSequenceUnavailable;
use App\Domain\Invoices\Exceptions\InvoiceIssueVersionConflict;
use App\Domain\Invoices\Exceptions\InvoiceNotDraft;
use App\Domain\Invoices\Exceptions\InvoiceNotReadyForIssue;
use App\Enums\BusinessAuditableType;
use App\Enums\InvoiceStatus;
use App\Http\Requests\InvoiceIndexRequest;
use App\Http\Requests\IssueInvoiceRequest;
use App\Http\Requests\PreviewInvoiceRequest;
use App\Http\Requests\StoreInvoiceDraftRequest;
use App\Http\Requests\UpdateInvoiceDraftRequest;
use App\Models\Business\Invoice;
use App\Services\Business\BusinessAuditService;
use App\Services\Business\InvoiceDraftEditor;
use App\Services\Business\InvoiceDraftService;
use App\Services\Business\InvoiceFormOptions;
use App\Services\Business\InvoiceIssuer;
use App\Services\Business\InvoicePreviewService;
use App\Services\Business\InvoiceReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

    public function store(StoreInvoiceDraftRequest $request, InvoiceDraftService $service): RedirectResponse
    {
        $invoice = $service->create($request->validated());

        return redirect()->route('invoices.show', $invoice->uuid)
            ->with('status', 'Návrh faktury byl vytvořen.');
    }

    public function show(
        string $uuid,
        InvoiceReader $reader,
        InvoiceFormOptions $options,
        BusinessAuditService $audit,
    ): View {
        Gate::authorize('view', Invoice::class);
        $invoice = $reader->find($uuid);
        $revision = $invoice->status === InvoiceStatus::Issued ? $invoice->issuedRevision : $invoice->currentRevision;
        $issueOptions = $invoice->status === InvoiceStatus::Draft
            ? $options->forDate($revision->issued_on->format('Y-m-d')) : [];

        return view('business.invoices.show', [
            'invoice' => $invoice,
            'revision' => $revision,
            'audits' => $audit->forEntity(BusinessAuditableType::Invoice, $invoice->uuid, 30),
            'issueCorrelationUuid' => (string) Str::uuid(),
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
        } catch (InvoiceNotReadyForIssue) {
            return back()->with('error', 'Fakturu nelze vystavit, protože není kompletní. Zkontrolujte její údaje.');
        } catch (InvoiceIssueSequenceUnavailable) {
            return back()->with('error', 'Vybraná číselná řada není dostupná.');
        } catch (InvoiceIssueVersionConflict) {
            return back()->with('error', 'Návrh mezitím změnil jiný uživatel. Načtěte aktuální verzi.');
        } catch (InvoiceIssueIdempotencyConflict) {
            return back()->with('error', 'Požadavek na vystavení má konfliktní identifikátor. Faktura nebyla změněna.');
        } catch (InvoiceNotDraft|InvoiceIssuedImmutable) {
            return redirect()->route('invoices.show', $invoice->uuid)
                ->with('error', 'Vystavenou fakturu nelze vystavit znovu jiným požadavkem.');
        }

        return redirect()->route('invoices.show', $issued->uuid)
            ->with('status', 'Faktura byla vystavena pod číslem '.$issued->document_number.'.');
    }

    public function preview(PreviewInvoiceRequest $request, InvoicePreviewService $preview): JsonResponse
    {
        return response()->json($preview->calculate($request->validated()));
    }
}
