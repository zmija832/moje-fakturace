<?php

namespace App\Http\Controllers;

use App\Domain\Invoices\Exceptions\InvoiceDeliveryIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceDocumentNotFound;
use App\Domain\Invoices\Exceptions\InvoiceEmailRecipientMissing;
use App\Domain\Invoices\Exceptions\InvoiceEmailSendFailed;
use App\Domain\Invoices\Exceptions\InvoiceNotIssuedForDelivery;
use App\Domain\Invoices\Exceptions\InvoicePdfGenerationFailed;
use App\Http\Requests\GenerateInvoicePdfRequest;
use App\Http\Requests\SendInvoiceEmailRequest;
use App\Models\Business\InvoiceDocument;
use App\Services\Business\InvoiceDocumentViewModelFactory;
use App\Services\Business\InvoiceMailer;
use App\Services\Business\InvoicePdfGenerator;
use App\Services\Business\InvoiceReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceDeliveryController extends Controller
{
    public function print(string $uuid, InvoiceReader $reader, InvoiceDocumentViewModelFactory $viewModels): View
    {
        $invoice = $reader->find($uuid);
        Gate::authorize('print', $invoice);

        return view('business.invoices.print', ['document' => $viewModels->make($invoice)->toArray(), 'archival' => false]);
    }

    public function download(string $uuid, InvoiceReader $reader, ?string $documentUuid = null): StreamedResponse
    {
        $invoice = $reader->find($uuid);
        Gate::authorize('downloadPdf', $invoice);
        $document = $documentUuid === null
            ? $invoice->documents->first()
            : $invoice->documents->firstWhere('uuid', $documentUuid);
        if (! $document instanceof InvoiceDocument || $document->storage_disk !== InvoicePdfGenerator::DISK || ! Storage::disk(InvoicePdfGenerator::DISK)->exists($document->storage_path)) {
            abort(404);
        }

        return Storage::disk(InvoicePdfGenerator::DISK)->download(
            $document->storage_path,
            $document->original_filename,
            ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function generate(GenerateInvoicePdfRequest $request, string $uuid, InvoiceReader $reader, InvoicePdfGenerator $generator): RedirectResponse
    {
        $invoice = $reader->find($uuid);
        Gate::authorize('generatePdf', $invoice);
        try {
            $document = $generator->generate(
                $invoice->uuid,
                (string) $request->validated('generation_correlation_uuid'),
                (bool) $request->validated('force_regenerate', false),
            );
        } catch (InvoicePdfGenerationFailed|InvoiceNotIssuedForDelivery|InvoiceDeliveryIdempotencyConflict $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('invoices.show', $invoice->uuid)
            ->with('status', 'PDF '.$document->original_filename.' bylo bezpečně vytvořeno.');
    }

    public function sendForm(string $uuid, InvoiceReader $reader): View
    {
        $invoice = $reader->find($uuid);
        Gate::authorize('sendEmail', $invoice);

        return view('business.invoices.send', [
            'invoice' => $invoice,
            'revision' => $invoice->issuedRevision,
            'sendCorrelationUuid' => (string) Str::uuid(),
        ]);
    }

    public function send(SendInvoiceEmailRequest $request, string $uuid, InvoiceReader $reader, InvoiceMailer $mailer): RedirectResponse
    {
        $invoice = $reader->find($uuid);
        Gate::authorize('sendEmail', $invoice);
        try {
            $delivery = $mailer->send(
                $invoice->uuid,
                (string) $request->validated('send_correlation_uuid'),
                $request->safe()->except(['send_correlation_uuid']),
            );
        } catch (InvoiceEmailRecipientMissing|InvoiceEmailSendFailed|InvoiceNotIssuedForDelivery|InvoiceDeliveryIdempotencyConflict|InvoiceDocumentNotFound|InvoicePdfGenerationFailed $exception) {
            return redirect()->route('invoices.show', $invoice->uuid)->with('error', $exception->getMessage());
        }

        return redirect()->route('invoices.show', $invoice->uuid)
            ->with('status', $delivery->status->value === 'sent' ? 'Faktura byla odeslána e-mailem.' : 'Požadavek již byl zaznamenán; e-mail nebyl odeslán podruhé.');
    }
}
