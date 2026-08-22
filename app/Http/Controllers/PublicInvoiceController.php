<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\Business\InvoiceDocument;
use App\Models\Business\InvoicePublicLink;
use App\Services\Business\InvoiceDocumentViewModelFactory;
use App\Services\Business\InvoicePaymentReader;
use App\Services\Business\InvoicePdfGenerator;
use App\Services\Business\InvoicePublicViewTracker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PublicInvoiceController extends Controller
{
    public function show(Request $request, InvoiceDocumentViewModelFactory $viewModels, InvoicePaymentReader $payments, InvoicePublicViewTracker $views): Response
    {
        $link = $this->link($request);
        $invoice = $link->invoice;
        $document = $invoice->currentPdfDocument();
        $hasPdf = $document instanceof InvoiceDocument
            && $document->storage_disk === InvoicePdfGenerator::DISK
            && Storage::disk(InvoicePdfGenerator::DISK)->exists($document->storage_path);

        $response = response()->view('public.invoices.show', [
            'document' => $viewModels->make($invoice)->toArray(),
            'hasPdf' => $hasPdf,
            'pdfUrl' => $hasPdf ? route('public-invoices.pdf', ['token' => $request->route('token')]) : null,
            'paymentSummary' => $payments->summary($invoice),
        ]);

        if ($request->user() === null) {
            try {
                $views->record($link);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $response;
    }

    public function pdf(Request $request): StreamedResponse
    {
        $document = $this->link($request)->invoice->currentPdfDocument();
        abort_unless($document instanceof InvoiceDocument
            && $document->storage_disk === InvoicePdfGenerator::DISK
            && Storage::disk(InvoicePdfGenerator::DISK)->exists($document->storage_path), 404);

        return Storage::disk(InvoicePdfGenerator::DISK)->download(
            $document->storage_path,
            $document->original_filename,
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
            ],
        );
    }

    private function link(Request $request): InvoicePublicLink
    {
        $link = InvoicePublicLink::query()->active()
            ->with(['invoice.documents'])
            ->findOrFail((int) $request->attributes->get('public_invoice_link_id'));
        abort_unless($link->invoice->status === InvoiceStatus::Issued, 404);

        return $link;
    }
}
