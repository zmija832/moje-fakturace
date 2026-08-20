<?php

namespace Tests\Unit;

use App\Enums\DefaultPaymentMethod;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceBankAccountSnapshot;
use App\Models\Business\InvoiceRevision;
use App\Services\Business\InvoiceQrPaymentService;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class InvoiceQrPaymentServiceTest extends TestCase
{
    public function test_official_spd_payload_uses_exact_snapshot_values_without_float(): void
    {
        if (! class_exists(Writer::class)) {
            $this->markTestSkipped('bacon/bacon-qr-code není nainstalován ve vendor.');
        }
        $invoice = new Invoice;
        $invoice->forceFill(['id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111', 'status' => InvoiceStatus::Issued->value, 'issued_revision_id' => 7, 'document_number' => 'FV-202600001']);
        $revision = new InvoiceRevision;
        $revision->setDateFormat('Y-m-d H:i:s');
        $revision->forceFill(['id' => 7, 'payment_method' => DefaultPaymentMethod::BankTransfer->value, 'currency' => 'CZK', 'grand_total' => '1234.5600', 'variable_symbol' => '1234567890', 'due_on' => CarbonImmutable::parse('2026-08-16')]);
        $account = new InvoiceBankAccountSnapshot;
        $account->forceFill(['iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX']);
        $revision->setRelation('bankAccountSnapshot', $account);

        $result = (new InvoiceQrPaymentService)->create($invoice, $revision);

        $this->assertTrue($result->available);
        $this->assertSame('SPD*1.0*ACC:CZ6508000000192000145399+GIBACZPX*AM:1234.56*CC:CZK*DT:20260816*MSG:Faktura%20FV-202600001*X-VS:1234567890', $result->payload);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $result->svgDataUri);
    }

    public function test_unsupported_or_invalid_snapshot_is_fail_closed(): void
    {
        $invoice = new Invoice;
        $invoice->forceFill(['id' => 1, 'status' => InvoiceStatus::Issued->value, 'issued_revision_id' => 7]);
        $revision = new InvoiceRevision;
        $revision->forceFill(['id' => 7, 'payment_method' => DefaultPaymentMethod::Cash->value, 'currency' => 'CZK', 'grand_total' => '100.0000']);

        $result = (new InvoiceQrPaymentService)->create($invoice, $revision);

        $this->assertFalse($result->available);
        $this->assertNull($result->payload);
        $this->assertNotEmpty($result->reason);
    }

    public function test_invalid_iban_or_variable_symbol_never_creates_payload(): void
    {
        $invoice = new Invoice;
        $invoice->forceFill(['id' => 1, 'status' => InvoiceStatus::Issued->value, 'issued_revision_id' => 7, 'document_number' => 'FV-1']);
        $revision = new InvoiceRevision;
        $revision->setDateFormat('Y-m-d H:i:s');
        $revision->forceFill([
            'id' => 7,
            'payment_method' => DefaultPaymentMethod::BankTransfer->value,
            'currency' => 'CZK',
            'grand_total' => '100.0000',
            'variable_symbol' => '12ABC',
            'due_on' => CarbonImmutable::parse('2026-08-16'),
        ]);
        $account = new InvoiceBankAccountSnapshot;
        $account->forceFill(['iban' => 'CZ0000000000000000000000', 'bic' => 'GIBACZPX']);
        $revision->setRelation('bankAccountSnapshot', $account);

        $result = (new InvoiceQrPaymentService)->create($invoice, $revision);

        $this->assertFalse($result->available);
        $this->assertNull($result->payload);
        $this->assertNull($result->svgDataUri);
    }

    public function test_draft_zero_amount_and_unsupported_currency_are_unavailable(): void
    {
        $invoice = new Invoice;
        $invoice->forceFill(['id' => 1, 'status' => InvoiceStatus::Draft->value, 'issued_revision_id' => null]);
        $revision = new InvoiceRevision;
        $revision->forceFill([
            'id' => 7,
            'payment_method' => DefaultPaymentMethod::BankTransfer->value,
            'currency' => 'EUR',
            'grand_total' => '0.0000',
        ]);

        $result = (new InvoiceQrPaymentService)->create($invoice, $revision);

        $this->assertFalse($result->available);
        $this->assertNotEmpty($result->reason);
    }

    public function test_issued_payment_fails_closed_for_missing_account_invalid_amount_or_currency(): void
    {
        $invoice = new Invoice;
        $invoice->forceFill([
            'id' => 1,
            'status' => InvoiceStatus::Issued->value,
            'issued_revision_id' => 7,
            'document_number' => 'FV-202600001',
        ]);
        $revision = new InvoiceRevision;
        $revision->setDateFormat('Y-m-d H:i:s');
        $revision->forceFill([
            'id' => 7,
            'payment_method' => DefaultPaymentMethod::BankTransfer->value,
            'currency' => 'CZK',
            'grand_total' => '100.0000',
            'due_on' => CarbonImmutable::parse('2026-08-16'),
        ]);
        $revision->setRelation('bankAccountSnapshot', null);
        $service = new InvoiceQrPaymentService;

        $this->assertFalse($service->create($invoice, $revision)->available);

        $account = new InvoiceBankAccountSnapshot;
        $account->forceFill(['iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX']);
        $revision->setRelation('bankAccountSnapshot', $account);

        $revision->grand_total = '0.0000';
        $this->assertFalse($service->create($invoice, $revision)->available);

        $revision->grand_total = '-0.0100';
        $this->assertFalse($service->create($invoice, $revision)->available);

        $revision->grand_total = '100.0000';
        $revision->currency = 'EUR';
        $this->assertFalse($service->create($invoice, $revision)->available);
    }
}
