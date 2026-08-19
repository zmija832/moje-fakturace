<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInvoiceAutomationSettingRequest;
use App\Models\Business\InvoiceAutomationSetting;
use App\Services\Business\AutomationTemplateRenderer;
use App\Services\Business\InvoiceAutomationSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InvoiceAutomationSettingController extends Controller
{
    public function edit(InvoiceAutomationSettingsService $service): View
    {
        Gate::authorize('viewAny', InvoiceAutomationSetting::class);

        return view('business.automation-settings.edit', ['setting' => $service->current(), 'reminderPlaceholders' => AutomationTemplateRenderer::REMINDER_PLACEHOLDERS, 'paidPlaceholders' => AutomationTemplateRenderer::PAID_PLACEHOLDERS]);
    }

    public function update(UpdateInvoiceAutomationSettingRequest $request, InvoiceAutomationSettingsService $service): RedirectResponse
    {
        $service->save($request->validated());

        return back()->with('status', 'Nastavení automatizace bylo uloženo.');
    }
}
