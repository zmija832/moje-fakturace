<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelInvoiceRequest;
use App\Http\Requests\DeleteInvoiceDraftRequest;
use App\Http\Requests\PurgeTestInvoiceRequest;
use App\Services\Business\InvoiceCancellationService;
use App\Services\Business\InvoiceDraftDeletionService;
use App\Services\Business\InvoiceReader;
use App\Services\Business\InvoiceTestPurgeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InvoiceLifecycleController extends Controller
{
    public function cancel(
        CancelInvoiceRequest $request,
        string $uuid,
        InvoiceReader $reader,
        InvoiceCancellationService $cancellation,
    ): RedirectResponse {
        $invoice = $reader->find($uuid);
        Gate::authorize('cancel', $invoice);

        try {
            $cancellation->cancel(
                $invoice->uuid,
                (int) $request->validated('expected_version'),
                (string) $request->validated('correlation_uuid'),
                (string) $request->validated('reason'),
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()->route('invoices.show', $invoice->uuid)
            ->with('status', 'Faktura byla stornována. Číslo dokladu a historie zůstaly zachovány.');
    }

    public function deleteDraft(
        DeleteInvoiceDraftRequest $request,
        string $uuid,
        InvoiceReader $reader,
        InvoiceDraftDeletionService $deletion,
    ): RedirectResponse {
        $invoice = $reader->find($uuid);
        Gate::authorize('deleteDraft', $invoice);

        try {
            $deletion->delete($invoice->uuid);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return redirect()->route('invoices.index')->with('status', 'Koncept byl trvale odstraněn.');
    }

    public function purgeTest(
        PurgeTestInvoiceRequest $request,
        string $uuid,
        InvoiceReader $reader,
        InvoiceTestPurgeService $purge,
    ): RedirectResponse {
        $invoice = $reader->find($uuid);
        Gate::authorize('purgeTest', $invoice);

        try {
            $documentNumber = $purge->purge($invoice->uuid, (string) $request->validated('document_number'));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()->route('invoices.index')->with(
            'status',
            "Testovací faktura {$documentNumber} byla trvale odstraněna. Její číslo zůstává navždy použité.",
        );
    }
}
