<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceMailStyle;
use App\Enums\InvoicePdfStyle;
use App\Http\Requests\PreviewInvoiceEmailSettingRequest;
use App\Http\Requests\PreviewInvoicePdfRequest;
use App\Http\Requests\UpdateInvoiceEmailSettingRequest;
use App\Models\Business\InvoiceEmailSetting;
use App\Services\Business\InvoiceEmailSettingsService;
use App\Services\Business\InvoiceEmailTemplateRenderer;
use App\Services\Business\InvoiceMailDesignRenderer;
use App\Services\Business\InvoicePdfRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InvoiceEmailSettingController extends Controller
{
    public function edit(InvoiceEmailSettingsService $settings, InvoiceMailDesignRenderer $branding): View
    {
        Gate::authorize('viewAny', InvoiceEmailSetting::class);

        return view('business.invoice-email-settings.edit', [
            'setting' => $settings->current(),
            'placeholders' => InvoiceEmailTemplateRenderer::PLACEHOLDERS,
            'styles' => InvoiceMailStyle::cases(),
            'pdfStyles' => InvoicePdfStyle::cases(),
            'logoAvailable' => $branding->logoAttachment() !== null,
        ]);
    }

    public function previewEmail(
        PreviewInvoiceEmailSettingRequest $request,
        InvoiceEmailSettingsService $settings,
        InvoiceEmailTemplateRenderer $renderer,
        InvoiceMailDesignRenderer $branding,
    ): Response {
        $setting = new InvoiceEmailSetting($settings->current()->getAttributes());
        $setting->fill($request->safe()->except(['logo', 'remove_logo']));
        $hasLogo = ! $request->boolean('remove_logo') && $branding->logoAttachment() !== null;
        $preview = $renderer->preview($setting, $hasLogo);
        $html = $hasLogo
            ? str_replace('__MAIL_LOGO__', route('invoice-email-settings.logo'), $preview['body_html'])
            : $preview['body_html'];

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; frame-ancestors 'self'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function previewPdf(PreviewInvoicePdfRequest $request, InvoicePdfRenderer $renderer): Response
    {
        $style = InvoicePdfStyle::from((string) $request->validated('pdf_style'));

        return response($renderer->renderPreviewHtml($style), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors 'self'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function logo(InvoiceMailDesignRenderer $branding): Response
    {
        Gate::authorize('viewAny', InvoiceEmailSetting::class);
        $logo = $branding->logoAttachment();
        abort_if($logo === null, 404);

        return response($logo['data'], 200, [
            'Content-Type' => $logo['mime'],
            'Content-Disposition' => 'inline; filename="'.$logo['name'].'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(UpdateInvoiceEmailSettingRequest $request, InvoiceEmailSettingsService $settings): RedirectResponse
    {
        $validated = $request->safe()->except(['logo', 'remove_logo']);
        $settings->save($validated, $request->file('logo'), $request->boolean('remove_logo'));

        return redirect()->route('invoice-email-settings.edit')->with('status', 'Nastavení e-mailů bylo uloženo.');
    }
}
