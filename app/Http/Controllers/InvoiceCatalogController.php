<?php

namespace App\Http\Controllers;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Domain\Invoices\InvoiceDecimal;
use App\Enums\VatTaxType;
use App\Http\Requests\InvoiceCatalogItemRequest;
use App\Http\Requests\InvoiceCatalogSearchRequest;
use App\Models\Business\CompanySetting;
use App\Models\Business\InvoiceCatalogItem;
use App\Models\Business\VatRate;
use App\Services\Business\InvoiceCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InvoiceCatalogController extends Controller
{
    public function index(Request $request, InvoiceCatalogService $service): View
    {
        Gate::authorize('viewAny', InvoiceCatalogItem::class);
        $filters = $request->only(['q', 'status']);

        return view('business.invoice-catalog.index', ['items' => $service->paginate($filters), 'filters' => $filters]);
    }

    public function create(): View
    {
        Gate::authorize('create', InvoiceCatalogItem::class);

        return view('business.invoice-catalog.create', $this->formData(new InvoiceCatalogItem(['currency' => 'CZK', 'unit' => 'ks', 'is_active' => true])));
    }

    public function store(InvoiceCatalogItemRequest $request, InvoiceCatalogService $service): RedirectResponse
    {
        $item = $service->create($request->validated());

        return redirect()->route('invoice-catalog.edit', $item->uuid)->with('status', 'Položka byla vytvořena.');
    }

    public function edit(string $uuid, InvoiceCatalogService $service): View
    {
        Gate::authorize('updateAny', InvoiceCatalogItem::class);

        return view('business.invoice-catalog.edit', $this->formData($service->find($uuid)));
    }

    public function update(InvoiceCatalogItemRequest $request, string $uuid, InvoiceCatalogService $service): RedirectResponse
    {
        $service->update($uuid, $request->validated());

        return back()->with('status', 'Položka byla uložena.');
    }

    public function activate(string $uuid, InvoiceCatalogService $service): RedirectResponse
    {
        Gate::authorize('updateAny', InvoiceCatalogItem::class);
        $service->setActive($uuid, true);

        return back()->with('status', 'Položka byla aktivována.');
    }

    public function deactivate(string $uuid, InvoiceCatalogService $service): RedirectResponse
    {
        Gate::authorize('updateAny', InvoiceCatalogItem::class);
        $service->setActive($uuid, false);

        return back()->with('status', 'Položka byla deaktivována.');
    }

    public function search(InvoiceCatalogSearchRequest $request, InvoiceCatalogService $service): JsonResponse
    {
        $items = $service->search((string) $request->validated('q', ''), (string) $request->validated('currency'));

        return response()->json(['items' => $items->map(fn (InvoiceCatalogItem $item): array => [
            'uuid' => $item->uuid, 'name' => $item->name,
            'unit_price' => InvoiceDecimal::formatInput($item->unit_price), 'unit' => $item->unit,
            'currency' => $item->currency, 'vat_rate_uuid' => $item->vat_rate_uuid,
            'label' => $item->name.' — '.InvoiceDecimal::formatMoney($item->unit_price, $item->currency).' / '.$item->unit,
        ])->values()]);
    }

    private function formData(InvoiceCatalogItem $item): array
    {
        $payer = (bool) CompanySetting::query()->where('singleton_key', CompanySetting::SINGLETON_KEY)->value('is_vat_payer');

        return ['item' => $item, 'currencies' => CompanySettingOptions::CURRENCIES, 'isVatPayer' => $payer,
            'vatRates' => $payer ? VatRate::query()
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->where('tax_type', '!=', VatTaxType::NonPayer->value)
                ->orderBy('name')
                ->get() : collect()];
    }
}
