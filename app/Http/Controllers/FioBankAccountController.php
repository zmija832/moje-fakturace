<?php

namespace App\Http\Controllers;

use App\Http\Requests\FioBankAccountSettingRequest;
use App\Models\Business\BankAccount;
use App\Services\Business\BusinessDate;
use App\Services\Business\FioApiClient;
use App\Services\Business\FioBankAccountSettingService;
use App\Services\Business\FioBankSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

class FioBankAccountController extends Controller
{
    public function update(FioBankAccountSettingRequest $request, string $uuid, FioBankAccountSettingService $service): RedirectResponse
    {
        $service->save($uuid, $request->boolean('is_enabled'), $request->validated('token'));

        return back()->with('status', 'Nastavení Fio integrace bylo uloženo.');
    }

    public function test(string $uuid, FioApiClient $client, BusinessDate $date): RedirectResponse
    {
        Gate::authorize('updateAny', BankAccount::class);
        $account = BankAccount::query()->with('fioSetting')->where('uuid', $uuid)->whereNull('archived_at')->firstOrFail();
        if (blank($account->fioSetting?->encrypted_token)) {
            return back()->withErrors(['fio' => 'Nejprve uložte Fio API token.']);
        }
        try {
            $today = $date->today();
            $statement = $client->transactions((string) $account->fioSetting->encrypted_token, $today, $today);
            if ($statement['account']['currency'] !== null && $statement['account']['currency'] !== $account->currency) {
                return back()->withErrors(['fio' => 'Token odpovídá účtu v jiné měně.']);
            }
        } catch (Throwable) {
            return back()->withErrors(['fio' => 'Spojení s Fio API se nepodařilo ověřit.']);
        }

        return back()->with('status', 'Spojení s Fio API je funkční.');
    }

    public function sync(string $uuid, FioBankSyncService $service, BusinessDate $date): RedirectResponse
    {
        Gate::authorize('updateAny', BankAccount::class);
        $account = BankAccount::query()->with('fioSetting.bankAccount')->where('uuid', $uuid)->firstOrFail();
        if (! $account->is_active || $account->archived_at !== null || ! $account->fioSetting?->is_enabled) {
            return back()->withErrors(['fio' => 'Fio synchronizace pro tento účet není zapnutá.']);
        }
        try {
            $result = $service->sync($account->fioSetting, $date->today());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['fio' => 'Synchronizace Fio účtu selhala. Podrobnost je bezpečně zaznamenána v logu.']);
        }

        return back()->with('status', "Synchronizace dokončena: nové {$result['new']}, spárované {$result['matched']}, nespárované {$result['unmatched']}.");
    }
}
