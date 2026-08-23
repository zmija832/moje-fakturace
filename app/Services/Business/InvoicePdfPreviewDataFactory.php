<?php

namespace App\Services\Business;

use App\Models\Business\CompanySetting;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class InvoicePdfPreviewDataFactory
{
    /** @return array<string, mixed> */
    public function make(): array
    {
        $company = CompanySetting::query()->where('singleton_key', CompanySetting::SINGLETON_KEY)->first();
        $supplier = trim((string) $company?->legal_name) ?: 'Ukázkový dodavatel s.r.o.';

        return [
            'template_version' => 'invoice-preview',
            'locale' => 'cs',
            'document_number' => 'FA-202600123',
            'status_label' => 'Vystavená',
            'issued_on' => '23. 8. 2026',
            'taxable_supply_on' => '23. 8. 2026',
            'due_on' => '6. 9. 2026',
            'variable_symbol' => '202600123',
            'payment_method' => 'Bankovní převod',
            'currency' => 'CZK',
            'currency_label' => 'CZK',
            'is_non_payer' => true,
            'supplier' => ['name' => $supplier, 'additional_name' => null, 'registration_number' => '12345678', 'tax_id' => null, 'vat_id' => null, 'address' => 'Ukázková 12, 110 00 Praha, CZ', 'email' => 'fakturace@example.invalid', 'phone' => '+420 000 000 000', 'is_vat_payer' => false],
            'customer' => ['name' => 'Jan Novák', 'company_name' => 'Ukázkový odběratel', 'registration_number' => '87654321', 'tax_id' => null, 'vat_id' => null, 'address' => 'Dlouhá zákaznická 123, 602 00 Brno, CZ', 'delivery_address' => null, 'email' => 'jan.novak@example.invalid', 'contact_person' => 'Jan Novák'],
            'bank_account' => ['name' => 'Ukázkový účet', 'domestic' => '123456789/0100', 'iban' => 'CZ0000000000000000000000', 'bic' => 'KOMBCZPP', 'currency' => 'CZK'],
            'items' => [
                ['position' => 1, 'description' => 'Grafické práce a příprava podkladů s delším názvem položky', 'quantity' => '2', 'unit' => 'hod', 'unit_price' => '500', 'discount' => '100', 'tax_base' => '900', 'tax_label' => 'Neplátce DPH', 'vat_amount' => '0', 'total' => '900'],
                ['position' => 2, 'description' => 'Tisk prezentačních materiálů', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '250', 'discount' => '0', 'tax_base' => '250', 'tax_label' => 'Neplátce DPH', 'vat_amount' => '0', 'total' => '250'],
                ['position' => 3, 'description' => 'Doprava a předání zakázky', 'quantity' => '1', 'unit' => 'služba', 'unit_price' => '100', 'discount' => '0', 'tax_base' => '100', 'tax_label' => 'Neplátce DPH', 'vat_amount' => '0', 'total' => '100'],
            ],
            'vat_summaries' => [],
            'totals' => ['subtotal_before_discount' => '1 350', 'discount_total' => '100', 'tax_base_total' => '1 250', 'vat_total' => '0', 'total_before_rounding' => '1 250', 'rounding_adjustment' => '0', 'grand_total' => '1 250', 'has_discount' => true, 'has_rounding' => false],
            'intro' => 'Děkujeme za spolupráci. Níže uvádíme přehled fakturovaných položek.',
            'outro' => 'Tento dokument je pouze bezpečný náhled vzhledu a není účetním dokladem.',
            'note' => 'Ukázková poznámka pro kontrolu delšího textu a rozložení faktury.',
            'qr' => ['available' => true, 'payload' => 'PREVIEW-NENI-PLATBA', 'svg_data_uri' => $this->previewQr(), 'reason' => null],
        ];
    }

    private function previewQr(): string
    {
        $renderer = new ImageRenderer(new RendererStyle(240, 8), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString(
            'PREVIEW-NENI-PLATBA',
            Encoder::DEFAULT_BYTE_MODE_ENCODING,
            ErrorCorrectionLevel::M(),
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
