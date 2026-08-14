<?php

namespace Tests\Unit;

use App\Enums\DefaultPaymentMethod;
use App\Enums\InvoiceStatus;
use App\Enums\VatTaxType;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceCustomerSnapshot;
use App\Models\Business\InvoiceItem;
use App\Models\Business\InvoiceRevision;
use App\Models\Business\InvoiceSupplierSnapshot;
use App\Models\Business\InvoiceVatSnapshot;
use App\Models\Business\InvoiceVatSummary;
use App\Services\Business\InvoiceDocumentViewModelFactory;
use App\Services\Business\InvoiceQrPaymentService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class InvoiceDocumentViewModelTest extends TestCase
{
    public function test_view_model_uses_only_explicit_issued_snapshot_values_and_decimal_strings(): void
    {
        $invoice = new Invoice;
        $invoice->forceFill([
            'id' => 10,
            'status' => InvoiceStatus::Issued->value,
            'issued_revision_id' => 20,
            'document_number' => 'FV-202600001',
        ]);
        $revision = new class extends InvoiceRevision
        {
            public function loadMissing($relations): static
            {
                return $this;
            }
        };
        $revision->setDateFormat('Y-m-d H:i:s');
        $revision->forceFill([
            'id' => 20,
            'issued_on' => '2026-08-02',
            'taxable_supply_on' => '2026-08-02',
            'due_on' => '2026-08-16',
            'payment_method' => DefaultPaymentMethod::Cash->value,
            'currency' => 'CZK',
            'variable_symbol' => '20260001',
            'subtotal_before_discount' => '100.0000',
            'discount_total' => '10.0000',
            'tax_base_total' => '90.0000',
            'vat_total' => '0.0000',
            'total_before_rounding' => '90.0000',
            'rounding_adjustment' => '0.0000',
            'grand_total' => '90.0000',
            'note' => 'Neměnná poznámka',
        ]);
        $supplier = new InvoiceSupplierSnapshot;
        $supplier->forceFill([
            'legal_name' => 'Žluťoučký dodavatel s.r.o.',
            'registration_number' => '12345678',
            'street' => 'Dodavatelská',
            'house_number' => '10',
            'city' => 'Praha',
            'postal_code' => '11000',
            'country_code' => 'CZ',
            'is_vat_payer' => false,
            'invoice_intro' => 'Úvod',
            'invoice_outro' => 'Závěr',
            'internal_secret' => 'nesmí uniknout',
        ]);
        $customer = new InvoiceCustomerSnapshot;
        $customer->forceFill([
            'display_name' => 'Příliš žluťoučký klient',
            'street' => 'Klientská',
            'house_number' => '1',
            'city' => 'Brno',
            'postal_code' => '60200',
            'country_code' => 'CZ',
        ]);
        $vat = new InvoiceVatSnapshot;
        $vat->forceFill(['tax_type' => VatTaxType::NonPayer->value, 'percentage' => null]);
        $item = new InvoiceItem;
        $item->forceFill([
            'position' => 1,
            'description' => 'Služba',
            'quantity' => '1.0000',
            'unit' => 'ks',
            'unit_price' => '100.0000',
            'line_discount_amount' => '10.0000',
            'invoice_discount_amount' => '0.0000',
            'line_net_amount' => '90.0000',
            'vat_amount' => '0.0000',
            'line_total_amount' => '90.0000',
        ]);
        $item->setRelation('vatSnapshot', $vat);
        $summary = new InvoiceVatSummary;
        $summary->forceFill([
            'tax_type' => VatTaxType::NonPayer->value,
            'percentage' => null,
            'tax_base' => '90.0000',
            'vat_amount' => '0.0000',
            'total_amount' => '90.0000',
        ]);
        $revision->setRelations([
            'supplierSnapshot' => $supplier,
            'customerSnapshot' => $customer,
            'bankAccountSnapshot' => null,
            'items' => new Collection([$item]),
            'vatSummaries' => new Collection([$summary]),
        ]);
        $invoice->setRelation('issuedRevision', $revision);

        $document = (new InvoiceDocumentViewModelFactory(new InvoiceQrPaymentService))->make($invoice)->toArray();

        $this->assertSame('Žluťoučký dodavatel s.r.o.', $document['supplier']['name']);
        $this->assertSame('90,00', $document['totals']['tax_base_total']);
        $this->assertSame('90,00', $document['totals']['grand_total']);
        $this->assertSame('Neplátce DPH', $document['items'][0]['tax_label']);
        $this->assertSame('Neplátce DPH', $document['vat_summaries'][0]['tax_label']);
        $this->assertTrue($document['is_non_payer']);
        $this->assertTrue($document['totals']['has_discount']);
        $this->assertFalse($document['qr']['available']);
        $this->assertArrayNotHasKey('internal_secret', $document['supplier']);
        $this->assertStringNotContainsString('nesmí uniknout', json_encode($document, JSON_THROW_ON_ERROR));
    }
}
