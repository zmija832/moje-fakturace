<?php

namespace App\Services\Business;

use App\Domain\Invoices\InvoiceDecimal;
use App\Domain\Invoices\InvoiceQrPayment;
use App\Enums\DefaultPaymentMethod;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceRevision;
use App\Rules\ValidIban;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Throwable;

class InvoiceQrPaymentService
{
    public function create(Invoice $invoice, InvoiceRevision $revision): InvoiceQrPayment
    {
        if ($invoice->status !== InvoiceStatus::Issued || (int) $invoice->issued_revision_id !== (int) $revision->id) {
            return InvoiceQrPayment::unavailable('QR Platba je dostupná pouze pro vystavenou revizi.');
        }
        if ($revision->payment_method !== DefaultPaymentMethod::BankTransfer || $revision->currency !== 'CZK') {
            return InvoiceQrPayment::unavailable('QR Platba je podporována pouze pro bankovní převod v CZK.');
        }
        if (InvoiceDecimal::compare($revision->grand_total, '0') <= 0) {
            return InvoiceQrPayment::unavailable('QR Platba vyžaduje kladnou částku.');
        }

        $account = $revision->bankAccountSnapshot;
        $iban = strtoupper(preg_replace('/\s+/', '', (string) $account?->iban) ?? '');
        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) || ! ValidIban::passesChecksum($iban)) {
            return InvoiceQrPayment::unavailable('Bankovní snapshot neobsahuje platný IBAN.');
        }
        $variableSymbol = trim((string) $revision->variable_symbol);
        if ($variableSymbol !== '' && ! preg_match('/^[0-9]{1,10}$/', $variableSymbol)) {
            return InvoiceQrPayment::unavailable('Variabilní symbol není použitelný pro QR Platbu.');
        }

        $bic = strtoupper(trim((string) $account?->bic));
        $acc = $iban.(preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic) ? '+'.$bic : '');
        $parts = [
            'SPD*1.0',
            'ACC:'.$acc,
            'AM:'.InvoiceDecimal::format($revision->grand_total, 2, '.'),
            'CC:CZK',
            'DT:'.$revision->due_on->format('Ymd'),
            'MSG:'.rawurlencode('Faktura '.$invoice->document_number),
        ];
        if ($variableSymbol !== '') {
            $parts[] = 'X-VS:'.$variableSymbol;
        }
        $payload = implode('*', $parts);
        if (! class_exists(Writer::class)) {
            return InvoiceQrPayment::unavailable('QR Platbu se nepodařilo bezpečně vykreslit.');
        }
        try {
            $renderer = new ImageRenderer(
                new RendererStyle(300, 8),
                new SvgImageBackEnd,
            );
            $svg = (new Writer($renderer))->writeString(
                $payload,
                Encoder::DEFAULT_BYTE_MODE_ENCODING,
                ErrorCorrectionLevel::M(),
            );
        } catch (Throwable) {
            return InvoiceQrPayment::unavailable('QR Platbu se nepodařilo bezpečně vykreslit.');
        }

        return InvoiceQrPayment::available($payload, 'data:image/svg+xml;base64,'.base64_encode($svg));
    }
}
