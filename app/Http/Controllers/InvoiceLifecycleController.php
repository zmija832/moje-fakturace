<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelInvoiceRequest;
use App\Http\Requests\DeleteInvoiceRequest;
use App\Services\Business\InvoiceCancellationService;
use App\Services\Business\InvoiceDeletionService;
use App\Services\Business\InvoiceReader;
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

    public function delete(
        DeleteInvoiceRequest $request,
        string $uuid,
        InvoiceReader $reader,
        InvoiceDeletionService $deletion,
    ): RedirectResponse {
        $invoice = $reader->find($uuid);
        Gate::authorize('deletePermanently', $invoice);

        try {
            $result = $deletion->delete($invoice->uuid);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $label = $result['document_number'] === null ? 'Koncept' : "Faktura {$result['document_number']}";

        return redirect()->route('invoices.index')->with('status', "{$label} byla trvale odstraněna.");
    }
}
