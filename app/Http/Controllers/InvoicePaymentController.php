<?php

namespace App\Http\Controllers;

use App\Domain\Invoices\Exceptions\InvoicePaymentIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoicePaymentNotAllowed;
use App\Domain\Invoices\Exceptions\InvoicePaymentReversalInvalid;
use App\Http\Requests\ReverseInvoicePaymentRequest;
use App\Http\Requests\StoreInvoicePaymentRequest;
use App\Services\Business\InvoicePaymentService;
use App\Services\Business\InvoiceReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class InvoicePaymentController extends Controller
{
    public function store(
        StoreInvoicePaymentRequest $request,
        string $uuid,
        InvoiceReader $reader,
        InvoicePaymentService $payments,
    ): RedirectResponse {
        $invoice = $reader->find($uuid);
        Gate::authorize('recordPayment', $invoice);

        try {
            $payments->record(
                $invoice->uuid,
                (string) $request->validated('correlation_uuid'),
                $request->safe()->except('correlation_uuid'),
            );
        } catch (InvoicePaymentNotAllowed) {
            return back()->with('error', 'Platbu lze evidovat pouze k vystavené faktuře.');
        } catch (InvoicePaymentIdempotencyConflict) {
            return back()->with('error', 'Opakovaný požadavek nelze bezpečně přiřadit k této platbě.');
        }

        return redirect()->route('invoices.show', $invoice->uuid)
            ->with('status', 'Platba byla bezpečně zaevidována.');
    }

    public function reverse(
        ReverseInvoicePaymentRequest $request,
        string $uuid,
        string $paymentUuid,
        InvoiceReader $reader,
        InvoicePaymentService $payments,
    ): RedirectResponse {
        $invoice = $reader->find($uuid);
        Gate::authorize('reversePayment', $invoice);

        try {
            $payments->reverse(
                $invoice->uuid,
                $paymentUuid,
                (string) $request->validated('correlation_uuid'),
                $request->safe()->except('correlation_uuid'),
            );
        } catch (InvoicePaymentNotAllowed) {
            return back()->with('error', 'Platbu lze stornovat pouze u vystavené faktury.');
        } catch (InvoicePaymentReversalInvalid) {
            return back()->with('error', 'Storno překračuje zbývající část původní platby.');
        } catch (InvoicePaymentIdempotencyConflict) {
            return back()->with('error', 'Opakovaný požadavek nelze bezpečně přiřadit k tomuto stornu.');
        }

        return redirect()->route('invoices.show', $invoice->uuid)
            ->with('status', 'Storno platby bylo vytvořeno jako nový záznam historie.');
    }
}
