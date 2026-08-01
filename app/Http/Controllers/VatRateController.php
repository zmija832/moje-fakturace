<?php

namespace App\Http\Controllers;

use App\Enums\BusinessAuditableType;
use App\Enums\VatRateDefaultContext;
use App\Enums\VatTaxType;
use App\Http\Requests\ManageVatRateRequest;
use App\Http\Requests\SetDefaultVatRateRequest;
use App\Http\Requests\StoreVatRateRequest;
use App\Http\Requests\UpdateVatRateRequest;
use App\Http\Requests\VatRateIndexRequest;
use App\Models\Business\VatRate;
use App\Services\Business\BusinessAuditService;
use App\Services\Business\VatRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class VatRateController extends Controller
{
    public function index(VatRateIndexRequest $request, VatRateService $service): View
    {
        return view('business.vat-rates.index', [
            'rates' => $service->search($request->validated()),
            'taxTypes' => VatTaxType::options(),
            'isVatPayer' => $service->isVatPayer(),
        ]);
    }

    public function create(VatRateService $service): View
    {
        Gate::authorize('create', VatRate::class);

        return view('business.vat-rates.create', $this->formData($service, $service->newForForm()));
    }

    public function store(StoreVatRateRequest $request, VatRateService $service): RedirectResponse
    {
        $rate = $service->create($request->validated());

        return redirect()->route('vat-rates.show', $rate->uuid)->with('status', 'Sazba DPH byla vytvořena.');
    }

    public function show(
        string $uuid,
        VatRateService $service,
        BusinessAuditService $auditService,
    ): View {
        Gate::authorize('view', VatRate::class);
        $rate = $service->find($uuid);

        return view('business.vat-rates.show', [
            'rate' => $rate,
            'isVatPayer' => $service->isVatPayer(),
            'auditLogs' => $auditService->forEntity(BusinessAuditableType::VatRate, $rate->uuid),
        ]);
    }

    public function edit(string $uuid, VatRateService $service): View
    {
        Gate::authorize('updateAny', VatRate::class);

        return view('business.vat-rates.edit', $this->formData($service, $service->findForEdit($uuid)));
    }

    public function update(UpdateVatRateRequest $request, string $uuid, VatRateService $service): RedirectResponse
    {
        $rate = $service->update($uuid, $request->validated());

        return redirect()->route('vat-rates.show', $rate->uuid)->with('status', 'Sazba DPH byla uložena.');
    }

    public function setDefault(SetDefaultVatRateRequest $request, string $uuid, VatRateService $service): RedirectResponse
    {
        $service->setDefault($uuid);

        return back()->with('status', 'Výchozí sazba pro prodej byla změněna.');
    }

    public function removeDefault(ManageVatRateRequest $request, VatRateService $service): RedirectResponse
    {
        $service->removeDefaultForContext(VatRateDefaultContext::Sales);

        return back()->with('status', 'Výchozí sazba pro prodej byla odebrána.');
    }

    public function deactivate(ManageVatRateRequest $request, string $uuid, VatRateService $service): RedirectResponse
    {
        $service->deactivate($uuid);

        return back()->with('status', 'Sazba DPH byla deaktivována.');
    }

    public function activate(ManageVatRateRequest $request, string $uuid, VatRateService $service): RedirectResponse
    {
        $service->activate($uuid);

        return back()->with('status', 'Sazba DPH byla aktivována.');
    }

    public function archive(ManageVatRateRequest $request, string $uuid, VatRateService $service): RedirectResponse
    {
        $service->archive($uuid);

        return redirect()->route('vat-rates.index')->with('status', 'Sazba DPH byla archivována.');
    }

    /** @return array<string, mixed> */
    private function formData(VatRateService $service, VatRate $rate): array
    {
        return ['rate' => $rate, 'taxTypes' => VatTaxType::options(), 'isVatPayer' => $service->isVatPayer()];
    }
}
