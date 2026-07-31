<?php

namespace App\Http\Controllers;

use App\Enums\DocumentSequenceResetPeriod;
use App\Enums\DocumentSequenceYearFormat;
use App\Enums\DocumentType;
use App\Http\Requests\ManageDocumentSequenceRequest;
use App\Http\Requests\SetDefaultDocumentSequenceRequest;
use App\Http\Requests\StoreDocumentSequenceRequest;
use App\Http\Requests\UpdateDocumentSequenceRequest;
use App\Models\Business\DocumentSequence;
use App\Services\Business\DocumentSequenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DocumentSequenceController extends Controller
{
    public function index(DocumentSequenceService $service): View
    {
        Gate::authorize('viewAny', DocumentSequence::class);
        $sequences = $service->all();
        $previewDate = today();

        return view('business.document-sequences.index', [
            'sequences' => $sequences,
            'previews' => $sequences->mapWithKeys(
                fn (DocumentSequence $sequence): array => [
                    $sequence->uuid => $service->previewModel($sequence, $previewDate),
                ],
            ),
        ]);
    }

    public function create(DocumentSequenceService $service): View
    {
        Gate::authorize('create', DocumentSequence::class);

        return view('business.document-sequences.create', $this->formData(
            $service,
            $service->newForForm(),
        ));
    }

    public function store(
        StoreDocumentSequenceRequest $request,
        DocumentSequenceService $service,
    ): RedirectResponse {
        $sequence = $service->create($request->validated());

        return redirect()->route('document-sequences.show', $sequence->uuid)
            ->with('status', 'Číselná řada byla vytvořena.');
    }

    public function show(string $uuid, DocumentSequenceService $service): View
    {
        Gate::authorize('view', DocumentSequence::class);
        $sequence = $service->find($uuid);

        return view('business.document-sequences.show', [
            'sequence' => $sequence,
            'preview' => $service->previewModel($sequence, today()),
            'lastAllocation' => $sequence->allocations->first(),
        ]);
    }

    public function edit(string $uuid, DocumentSequenceService $service): View
    {
        Gate::authorize('updateAny', DocumentSequence::class);

        return view('business.document-sequences.edit', $this->formData(
            $service,
            $service->findForEdit($uuid),
        ));
    }

    public function update(
        UpdateDocumentSequenceRequest $request,
        string $uuid,
        DocumentSequenceService $service,
    ): RedirectResponse {
        $sequence = $service->update($uuid, $request->validated());

        return redirect()->route('document-sequences.show', $sequence->uuid)
            ->with('status', 'Číselná řada byla uložena.');
    }

    public function setDefault(
        SetDefaultDocumentSequenceRequest $request,
        string $uuid,
        DocumentSequenceService $service,
    ): RedirectResponse {
        $service->setDefault($uuid);

        return back()->with('status', 'Výchozí číselná řada byla změněna.');
    }

    public function deactivate(
        ManageDocumentSequenceRequest $request,
        string $uuid,
        DocumentSequenceService $service,
    ): RedirectResponse {
        $service->deactivate($uuid);

        return back()->with('status', 'Číselná řada byla deaktivována.');
    }

    public function activate(
        ManageDocumentSequenceRequest $request,
        string $uuid,
        DocumentSequenceService $service,
    ): RedirectResponse {
        $service->activate($uuid);

        return back()->with('status', 'Číselná řada byla aktivována.');
    }

    public function archive(
        ManageDocumentSequenceRequest $request,
        string $uuid,
        DocumentSequenceService $service,
    ): RedirectResponse {
        $service->archive($uuid);

        return redirect()->route('document-sequences.index')
            ->with('status', 'Číselná řada byla archivována.');
    }

    /** @return array<string, mixed> */
    private function formData(DocumentSequenceService $service, DocumentSequence $sequence): array
    {
        return [
            'sequence' => $sequence,
            'documentTypes' => DocumentType::options(),
            'yearFormats' => DocumentSequenceYearFormat::options(),
            'resetPeriods' => DocumentSequenceResetPeriod::options(),
            'serverPreview' => $service->previewModel($sequence, today()),
        ];
    }
}
