<?php

namespace App\Http\Controllers;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Enums\ClientType;
use App\Enums\DefaultPaymentMethod;
use App\Http\Requests\ClientIndexRequest;
use App\Http\Requests\ManageClientRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Business\Client;
use App\Services\Business\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(ClientIndexRequest $request, ClientService $service): View
    {
        $filters = $request->validated();

        return view('business.clients.index', [
            'clients' => $service->search($filters),
            'filters' => $filters,
            'types' => ClientType::options(),
        ]);
    }

    public function create(ClientService $service): View
    {
        Gate::authorize('create', Client::class);

        return view('business.clients.create', $this->formData($service->newForForm()));
    }

    public function store(StoreClientRequest $request, ClientService $service): RedirectResponse|JsonResponse
    {
        $attributes = $request->validated();

        if ($request->expectsJson()) {
            $attributes['is_active'] = true;
        }

        $client = $service->create($attributes);

        if ($request->expectsJson()) {
            return response()->json(['client' => [
                'uuid' => $client->uuid,
                'display_name' => $client->display_name,
                'registration_number' => $client->registration_number,
                'default_currency' => $client->default_currency,
                'default_due_days' => $client->default_due_days,
                'default_payment_method' => $client->default_payment_method,
            ]], 201);
        }

        return redirect()->route('clients.show', $client->uuid)
            ->with('status', 'Klient byl vytvořen.');
    }

    public function show(string $uuid, ClientService $service): View
    {
        Gate::authorize('view', Client::class);

        return view('business.clients.show', ['client' => $service->find($uuid)]);
    }

    public function edit(string $uuid, ClientService $service): View
    {
        Gate::authorize('updateAny', Client::class);

        return view('business.clients.edit', $this->formData($service->findForEdit($uuid)));
    }

    public function update(UpdateClientRequest $request, string $uuid, ClientService $service): RedirectResponse
    {
        $client = $service->update($uuid, $request->validated());

        return redirect()->route('clients.show', $client->uuid)
            ->with('status', 'Klient byl uložen.');
    }

    public function deactivate(ManageClientRequest $request, string $uuid, ClientService $service): RedirectResponse
    {
        $service->deactivate($uuid);

        return back()->with('status', 'Klient byl deaktivován.');
    }

    public function activate(ManageClientRequest $request, string $uuid, ClientService $service): RedirectResponse
    {
        $service->activate($uuid);

        return back()->with('status', 'Klient byl aktivován.');
    }

    public function archive(ManageClientRequest $request, string $uuid, ClientService $service): RedirectResponse
    {
        $service->archive($uuid);

        return redirect()->route('clients.index', ['status' => 'archived'])
            ->with('status', 'Klient byl archivován.');
    }

    /** @return array<string, mixed> */
    private function formData(Client $client): array
    {
        return [
            'client' => $client,
            'types' => ClientType::options(),
            'countries' => CompanySettingOptions::COUNTRIES,
            'currencies' => CompanySettingOptions::CURRENCIES,
            'languages' => CompanySettingOptions::DOCUMENT_LOCALES,
            'paymentMethods' => DefaultPaymentMethod::options(),
        ];
    }
}
