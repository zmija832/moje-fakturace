<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceLabelColor;
use App\Http\Requests\AttachInvoiceLabelRequest;
use App\Http\Requests\InvoiceLabelRequest;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceLabel;
use App\Services\Business\InvoiceLabelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InvoiceLabelController extends Controller
{
    public function index(InvoiceLabelService $service): View
    {
        Gate::authorize('viewAny', InvoiceLabel::class);

        return view('business.invoice-labels.index', [
            'labels' => $service->all(),
            'colors' => InvoiceLabelColor::options(),
        ]);
    }

    public function store(InvoiceLabelRequest $request, InvoiceLabelService $service): RedirectResponse
    {
        $service->create($request->validated());

        return back()->with('status', 'Štítek byl vytvořen.');
    }

    public function update(InvoiceLabelRequest $request, string $uuid, InvoiceLabelService $service): RedirectResponse
    {
        $service->update($uuid, $request->validated());

        return back()->with('status', 'Štítek byl uložen.');
    }

    public function attach(AttachInvoiceLabelRequest $request, string $uuid, InvoiceLabelService $service): RedirectResponse
    {
        $service->attach($uuid, (string) $request->validated('label_uuid'));

        return back()->with('status', 'Štítek byl přiřazen.');
    }

    public function detach(string $uuid, string $labelUuid, InvoiceLabelService $service): RedirectResponse
    {
        Gate::authorize('updateAny', Invoice::class);
        $service->detach($uuid, $labelUuid);

        return back()->with('status', 'Štítek byl odebrán.');
    }
}
