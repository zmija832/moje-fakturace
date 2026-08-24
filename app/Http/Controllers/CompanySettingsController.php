<?php

namespace App\Http\Controllers;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\CompanySettings\CompanySettingOptions;
use App\Enums\BusinessThemeColor;
use App\Enums\DefaultPaymentMethod;
use App\Http\Requests\UpdateBusinessAppearanceRequest;
use App\Http\Requests\UpdateCompanySettingRequest;
use App\Models\Business\CompanySetting;
use App\Services\Business\BusinessAppearanceService;
use App\Services\Business\CompanySettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CompanySettingsController extends Controller
{
    public function edit(CompanySettingsService $service, ActiveBusinessContext $context): View
    {
        Gate::authorize('viewAny', CompanySetting::class);

        return view('business.company-settings.edit', [
            'setting' => $service->forForm(),
            'countries' => CompanySettingOptions::COUNTRIES,
            'currencies' => CompanySettingOptions::CURRENCIES,
            'documentLocales' => CompanySettingOptions::DOCUMENT_LOCALES,
            'timezones' => CompanySettingOptions::TIMEZONES,
            'paymentMethods' => DefaultPaymentMethod::options(),
            'activeBusiness' => $context->requireBusiness(),
            'businessThemeColors' => BusinessThemeColor::cases(),
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

    public function updateAppearance(
        UpdateBusinessAppearanceRequest $request,
        BusinessAppearanceService $service,
    ): RedirectResponse {
        $service->save(BusinessThemeColor::from((string) $request->validated('theme_color')));

        return redirect()
            ->route('company-settings.edit')
            ->with('status', 'Barva subjektu byla uložena.');
    }
}
