<?php

namespace App\Http\Controllers;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Enums\DefaultPaymentMethod;
use App\Http\Requests\UpdateCompanySettingRequest;
use App\Models\Business\CompanySetting;
use App\Services\Business\CompanySettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CompanySettingsController extends Controller
{
    public function edit(CompanySettingsService $service): View
    {
        Gate::authorize('viewAny', CompanySetting::class);

        return view('business.company-settings.edit', [
            'setting' => $service->forForm(),
            'countries' => CompanySettingOptions::COUNTRIES,
            'currencies' => CompanySettingOptions::CURRENCIES,
            'documentLocales' => CompanySettingOptions::DOCUMENT_LOCALES,
            'timezones' => CompanySettingOptions::TIMEZONES,
            'paymentMethods' => DefaultPaymentMethod::options(),
        ]);
    }

    public function update(
        UpdateCompanySettingRequest $request,
        CompanySettingsService $service,
    ): RedirectResponse {
        $service->save($request->validated());

        return redirect()
            ->route('company-settings.edit')
            ->with('status', 'Nastavení subjektu bylo uloženo.');
    }
}
