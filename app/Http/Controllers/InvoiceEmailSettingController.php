<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInvoiceEmailSettingRequest;
use App\Models\Business\InvoiceEmailSetting;
use App\Services\Business\InvoiceEmailSettingsService;
use App\Services\Business\InvoiceEmailTemplateRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InvoiceEmailSettingController extends Controller
{
    public function edit(InvoiceEmailSettingsService $settings): View
    {
        Gate::authorize('viewAny', InvoiceEmailSetting::class);

        return view('business.invoice-email-settings.edit', [
            'setting' => $settings->current(),
            'placeholders' => InvoiceEmailTemplateRenderer::PLACEHOLDERS,
        ]);
    }

    public function update(UpdateInvoiceEmailSettingRequest $request, InvoiceEmailSettingsService $settings): RedirectResponse
    {
        $settings->save($request->validated());

        return redirect()->route('invoice-email-settings.edit')->with('status', 'Nastavení e-mailů bylo uloženo.');
    }
}
