<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceReminderOrigin;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use App\Services\Business\AutomationTemplateRenderer;
use App\Services\Business\InvoiceAutomationSettingsService;
use App\Services\Business\InvoicePaymentReader;
use App\Services\Business\InvoiceReminderPreferenceService;
use App\Services\Business\InvoiceReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoiceReminderController extends Controller
{
    public function form(string $uuid, InvoiceAutomationSettingsService $settings, InvoicePaymentReader $payments, AutomationTemplateRenderer $renderer): View
    {
        $invoice = $this->invoice($uuid);
        Gate::authorize('recordPayment', $invoice);
        $invoice->load(['issuedRevision.customerSnapshot', 'issuedRevision.supplierSnapshot', 'reminders']);
        $summary = $payments->summary($invoice);
        abort_unless($summary->isOverdue, 422, 'Upomínku lze odeslat pouze k faktuře po splatnosti s nedoplatkem.');
        $setting = $settings->current();
        $days = (int) $invoice->due_on->diffInDays(today());
        $previews = collect([1, 2, 3])->mapWithKeys(fn ($level) => [$level => $renderer->reminder($invoice, $setting->{"reminder_subject_$level"}, $setting->{"reminder_body_$level"}, $summary->remainingTotal, $days)]);

        return view('business.invoices.reminder', compact('invoice', 'previews'));
    }

    public function send(Request $request, string $uuid, InvoiceReminderService $service): RedirectResponse
    {
        $invoice = $this->invoice($uuid);
        Gate::authorize('recordPayment', $invoice);
        $data = $request->validate(['level' => ['required', 'integer', 'between:1,3']]);
        try {
            $service->prepare(
                $invoice,
                (int) $data['level'],
                CarbonImmutable::today(),
                true,
                InvoiceReminderOrigin::Manual,
            );
        } catch (ValidationException $exception) {
            abort(422, collect($exception->errors())->flatten()->first() ?? 'Upomínku nelze odeslat.');
        }

        return redirect()->route('invoices.show', $uuid)->with('status', 'Upomínka byla zpracována.');
    }

    public function toggle(Request $request, string $uuid, InvoiceReminderPreferenceService $service): RedirectResponse
    {
        $invoice = $this->invoice($uuid);
        Gate::authorize('recordPayment', $invoice);
        $data = $request->validate(['disabled' => ['required', 'boolean']]);
        $service->set($invoice, (bool) $data['disabled'], 'central-user:'.$request->user()->id);

        return back()->with('status', 'Nastavení upomínek faktury bylo změněno.');
    }

    private function invoice(string $uuid): Invoice
    {
        $invoice = Invoice::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless($invoice->status === InvoiceStatus::Issued, 404);

        return $invoice;
    }
}
