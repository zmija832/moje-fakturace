<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\Exceptions\InvoiceDeliveryIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceNotIssuedForDelivery;
use App\Domain\Invoices\Exceptions\InvoicePdfGenerationFailed;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\InvoiceDocumentType;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class InvoicePdfGenerator
{
    public const DISK = 'invoice_documents';

    public const TEMPLATE_VERSION = 'invoice-v1';

    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly ActiveBusinessContext $businessContext,
        private readonly InvoiceReader $reader,
        private readonly InvoiceDocumentViewModelFactory $viewModels,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    public function generate(string $invoiceUuid, string $correlationUuid, bool $forceRegenerate = false): InvoiceDocument
    {
        if (! Str::isUuid($invoiceUuid) || ! Str::isUuid($correlationUuid)) {
            throw InvoicePdfGenerationFailed::create();
        }
        $connection = $this->connectionResolver->resolve()->connectionName();
        $invoice = $this->reader->find($invoiceUuid);
        if ($invoice->status !== InvoiceStatus::Issued) {
            throw InvoiceNotIssuedForDelivery::create();
        }
        $existing = InvoiceDocument::query()->where('generation_correlation_uuid', $correlationUuid)->first();
        if ($existing !== null) {
            if ((int) $existing->invoice_id !== (int) $invoice->id) {
                throw InvoiceDeliveryIdempotencyConflict::create();
            }

            return $existing;
        }
        $latest = $invoice->documents()->first();
        if (! $forceRegenerate && $latest !== null && Storage::disk(self::DISK)->exists($latest->storage_path)) {
            return $latest;
        }

        $documentUuid = (string) Str::uuid();
        $tempPath = 'tmp/'.$documentUuid.'.pdf';
        $year = $invoice->issuedRevision->issued_on->format('Y');
        $finalPath = $this->businessContext->requireBusiness()->uuid.'/'.$year.'/'.$invoice->uuid.'/'.$documentUuid.'.pdf';
        $moved = false;

        try {
            $pdf = $this->render($this->viewModels->make($invoice)->toArray());
            Storage::disk(self::DISK)->put($tempPath, $pdf);
            $hash = hash('sha256', $pdf);
            $size = strlen($pdf);

            $document = DB::connection($connection)->transaction(function () use ($invoice, $correlationUuid, $forceRegenerate, $documentUuid, $tempPath, $finalPath, $hash, $size, &$moved): InvoiceDocument {
                $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== InvoiceStatus::Issued) {
                    throw InvoiceNotIssuedForDelivery::create();
                }
                $existing = InvoiceDocument::query()->where('generation_correlation_uuid', $correlationUuid)->first();
                if ($existing !== null) {
                    if ((int) $existing->invoice_id !== (int) $locked->id) {
                        throw InvoiceDeliveryIdempotencyConflict::create();
                    }

                    return $existing;
                }
                $latest = $locked->documents()->first();
                if (! $forceRegenerate && $latest !== null && Storage::disk(self::DISK)->exists($latest->storage_path)) {
                    return $latest;
                }
                $document = new InvoiceDocument;
                $document->forceFill([
                    'uuid' => $documentUuid,
                    'invoice_id' => $locked->id,
                    'document_type' => InvoiceDocumentType::InvoicePdf->value,
                    'storage_disk' => self::DISK,
                    'storage_path' => $finalPath,
                    'original_filename' => $this->filename($locked->document_number),
                    'mime_type' => 'application/pdf',
                    'size_bytes' => $size,
                    'sha256' => $hash,
                    'template_version' => self::TEMPLATE_VERSION,
                    'generated_at' => now(),
                    'generated_by_actor' => $this->actor(),
                    'generation_correlation_uuid' => $correlationUuid,
                ])->save();
                if (Storage::disk(self::DISK)->exists($finalPath)) {
                    throw InvoicePdfGenerationFailed::create();
                }
                if (! Storage::disk(self::DISK)->move($tempPath, $finalPath)) {
                    throw InvoicePdfGenerationFailed::create();
                }
                $moved = true;
                $document->setRelation('invoice', $locked);
                $this->auditWriter->write(
                    BusinessAuditEvent::InvoicePdfGenerated,
                    BusinessAuditableType::InvoiceDocument,
                    $document->uuid,
                    null,
                    $this->auditSanitizer->snapshot(BusinessAuditableType::InvoiceDocument, $document),
                    ['generated'],
                    BusinessAuditableType::Invoice,
                    $locked->uuid,
                );

                return $document;
            }, 3);
            if (Storage::disk(self::DISK)->exists($tempPath)) {
                Storage::disk(self::DISK)->delete($tempPath);
            }

            return $document;
        } catch (Throwable $exception) {
            if (Storage::disk(self::DISK)->exists($tempPath)) {
                Storage::disk(self::DISK)->delete($tempPath);
            }
            if ($moved && Storage::disk(self::DISK)->exists($finalPath)) {
                Storage::disk(self::DISK)->delete($finalPath);
            }
            $this->auditFailure($connection, $invoice, $correlationUuid);
            if ($exception instanceof InvoiceNotIssuedForDelivery || $exception instanceof InvoiceDeliveryIdempotencyConflict) {
                throw $exception;
            }
            throw InvoicePdfGenerationFailed::create();
        }
    }

    private function render(array $data): string
    {
        if (! class_exists(Dompdf::class)) {
            throw InvoicePdfGenerationFailed::create();
        }
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('business.invoices.print', ['document' => $data, 'archival' => true])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $output = $dompdf->output();
        if (! str_starts_with($output, '%PDF-') || strlen($output) === 0) {
            throw InvoicePdfGenerationFailed::create();
        }

        return $output;
    }

    private function auditFailure(string $connection, Invoice $invoice, string $correlationUuid): void
    {
        try {
            DB::connection($connection)->transaction(fn () => $this->auditWriter->write(
                BusinessAuditEvent::InvoicePdfGenerationFailed,
                BusinessAuditableType::Invoice,
                $invoice->uuid,
                null,
                null,
                [],
                metadata: ['correlation_uuid' => $correlationUuid, 'failure_code' => 'pdf_generation_failed'],
            ));
        } catch (Throwable) {
        }
    }

    private function filename(?string $number): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $number) ?: 'faktura';

        return 'faktura-'.$safe.'.pdf';
    }

    private function actor(): ?string
    {
        $user = auth()->user();

        return $user ? 'central-user:'.$user->getAuthIdentifier() : null;
    }
}
