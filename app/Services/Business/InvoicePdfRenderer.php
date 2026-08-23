<?php

namespace App\Services\Business;

use App\Domain\Invoices\Exceptions\InvoicePdfGenerationFailed;
use App\Enums\InvoicePdfStyle;
use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;

final class InvoicePdfRenderer
{
    public function __construct(
        private readonly InvoiceEmailSettingsService $settings,
        private readonly InvoiceMailDesignRenderer $branding,
        private readonly InvoicePdfPreviewDataFactory $previewData,
    ) {}

    /** @param array<string, mixed> $document */
    public function renderPdf(array $document, ?InvoicePdfStyle $style = null): string
    {
        if (! class_exists(Dompdf::class)) {
            throw InvoicePdfGenerationFailed::create();
        }

        $style ??= $this->currentStyle();
        $logo = $this->branding->logoAttachment();

        try {
            return $this->renderBinary($this->renderHtmlWithLogo($document, $style, true, $logo));
        } catch (Throwable $exception) {
            if ($logo === null) {
                throw $exception;
            }

            report($exception);

            return $this->renderBinary($this->renderHtmlWithLogo($document, $style, true, null));
        }
    }

    private function renderBinary(string $html): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $output = $dompdf->output();
        if (! str_starts_with($output, '%PDF-') || strlen($output) === 0) {
            throw InvoicePdfGenerationFailed::create();
        }

        return $output;
    }

    public function renderPreview(InvoicePdfStyle $style): string
    {
        return $this->renderPdf($this->previewData->make(), $style);
    }

    /** @param array<string, mixed> $document */
    public function renderHtml(array $document, ?InvoicePdfStyle $style = null, bool $archival = false): string
    {
        $style ??= $this->currentStyle();

        return $this->renderHtmlWithLogo($document, $style, $archival, $this->branding->logoAttachment());
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array{data: string, mime: string, filename: string}|null  $logo
     */
    private function renderHtmlWithLogo(array $document, InvoicePdfStyle $style, bool $archival, ?array $logo): string
    {
        $logoDataUri = $logo === null ? null : 'data:'.$logo['mime'].';base64,'.base64_encode($logo['data']);

        return view('business.invoices.print', [
            'document' => $document,
            'archival' => $archival,
            'pdfStyle' => $style,
            'pdfTheme' => $style->theme(),
            'logoDataUri' => $logoDataUri,
        ])->render();
    }

    public function currentStyle(): InvoicePdfStyle
    {
        return InvoicePdfStyle::tryFrom((string) $this->settings->current()->pdf_style) ?? InvoicePdfStyle::Classic;
    }
}
