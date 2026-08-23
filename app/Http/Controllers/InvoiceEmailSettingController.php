<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceMailStyle;
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
            'styles' => InvoiceMailStyle::cases(),
        ]);
    }

    public function update(UpdateInvoiceEmailSettingRequest $request, InvoiceEmailSettingsService $settings): RedirectResponse
    {
        $validated = $request->safe()->except(['logo', 'remove_logo']);
        $settings->save($validated, $request->file('logo'), $request->boolean('remove_logo'));

        return redirect()->route('invoice-email-settings.edit')->with('status', 'Nastavení e-mailů bylo uloženo.');
    }
}
