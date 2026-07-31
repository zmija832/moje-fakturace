<?php

namespace App\Http\Controllers;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Http\Requests\ManageBankAccountRequest;
use App\Http\Requests\SetDefaultBankAccountRequest;
use App\Http\Requests\StoreBankAccountRequest;
use App\Http\Requests\UpdateBankAccountRequest;
use App\Models\Business\BankAccount;
use App\Services\Business\BankAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BankAccountController extends Controller
{
    public function index(BankAccountService $service): View
    {
        Gate::authorize('viewAny', BankAccount::class);

        [$currentAccounts, $archivedAccounts] = $service->all()
            ->partition(fn (BankAccount $account): bool => $account->archived_at === null);

        return view('business.bank-accounts.index', [
            'currentAccounts' => $currentAccounts,
            'archivedAccounts' => $archivedAccounts,
        ]);
    }

    public function create(BankAccountService $service): View
    {
        Gate::authorize('create', BankAccount::class);

        return view('business.bank-accounts.create', [
            'account' => $service->newForForm(),
            'currencies' => CompanySettingOptions::CURRENCIES,
        ]);
    }

    public function store(
        StoreBankAccountRequest $request,
        BankAccountService $service,
    ): RedirectResponse {
        $service->create($request->validated());

        return redirect()
            ->route('bank-accounts.index')
            ->with('status', 'Bankovní účet byl vytvořen.');
    }

    public function edit(string $uuid, BankAccountService $service): View
    {
        Gate::authorize('updateAny', BankAccount::class);

        return view('business.bank-accounts.edit', [
            'account' => $service->findForEdit($uuid),
            'currencies' => CompanySettingOptions::CURRENCIES,
        ]);
    }

    public function update(
        UpdateBankAccountRequest $request,
        string $uuid,
        BankAccountService $service,
    ): RedirectResponse {
        $service->update($uuid, $request->validated());

        return redirect()
            ->route('bank-accounts.index')
            ->with('status', 'Bankovní účet byl uložen.');
    }

    public function setDefault(
        SetDefaultBankAccountRequest $request,
        string $uuid,
        BankAccountService $service,
    ): RedirectResponse {
        $service->setDefault($uuid);

        return redirect()
            ->route('bank-accounts.index')
            ->with('status', 'Výchozí bankovní účet byl změněn.');
    }

    public function deactivate(
        ManageBankAccountRequest $request,
        string $uuid,
        BankAccountService $service,
    ): RedirectResponse {
        $service->deactivate($uuid);

        return redirect()
            ->route('bank-accounts.index')
            ->with('status', 'Bankovní účet byl deaktivován.');
    }

    public function activate(
        ManageBankAccountRequest $request,
        string $uuid,
        BankAccountService $service,
    ): RedirectResponse {
        $service->activate($uuid);

        return redirect()
            ->route('bank-accounts.index')
            ->with('status', 'Bankovní účet byl aktivován.');
    }

    public function archive(
        ManageBankAccountRequest $request,
        string $uuid,
        BankAccountService $service,
    ): RedirectResponse {
        $service->archive($uuid);

        return redirect()
            ->route('bank-accounts.index')
            ->with('status', 'Bankovní účet byl archivován.');
    }
}
