<?php

namespace App\Http\Controllers;

use App\Services\Business\InvoicePublicLinkService;
use App\Services\Business\InvoiceReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class InvoicePublicLinkController extends Controller
{
    public function store(string $uuid, InvoiceReader $reader, InvoicePublicLinkService $links): RedirectResponse
    {
        $invoice = $reader->find($uuid);
        Gate::authorize('managePublicLink', $invoice);
        $links->create($invoice);

        return redirect()->route('invoices.show', $invoice->uuid)->with('status', 'Webfaktura byla bezpečně zpřístupněna.');
    }

    public function regenerate(string $uuid, InvoiceReader $reader, InvoicePublicLinkService $links): RedirectResponse
    {
        $invoice = $reader->find($uuid);
        Gate::authorize('managePublicLink', $invoice);
        $links->regenerate($invoice);

        return redirect()->route('invoices.show', $invoice->uuid)->with('status', 'Odkaz Webfaktury byl obnoven. Původní odkaz již nefunguje.');
    }

    public function revoke(string $uuid, InvoiceReader $reader, InvoicePublicLinkService $links): RedirectResponse
    {
        $invoice = $reader->find($uuid);
        Gate::authorize('managePublicLink', $invoice);
        $links->revoke($invoice);

        return redirect()->route('invoices.show', $invoice->uuid)->with('status', 'Veřejný odkaz Webfaktury byl zrušen.');
    }
}
