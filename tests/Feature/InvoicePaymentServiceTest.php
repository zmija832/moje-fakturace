<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\Exceptions\MissingBusinessContext;
use App\Domain\Invoices\Exceptions\InvoicePaymentNotAllowed;
use App\Domain\Invoices\Exceptions\InvoicePaymentReversalInvalid;
use App\Events\InvoicePaymentChanged;
use App\Models\Business\InvoicePayment;
use App\Services\Business\BusinessAuditWriter;
use App\Services\Business\InvoicePaymentReader;
use App\Services\Business\InvoicePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoicePaymentServiceTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;
    use InteractsWithBusinessDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshBusinessTestDatabases();
    }

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_multiple_payments_are_exact_idempotent_reject_overpayment_and_emit_immutable_events(): void
    {
        Event::fake([InvoicePaymentChanged::class]);
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $service = app(InvoicePaymentService::class);
        $correlation = (string) Str::uuid();
        $first = $service->record($invoice->uuid, $correlation, $this->payload('40.1234'));
        $repeated = $service->record($invoice->uuid, $correlation, $this->payload('99'));
        $second = $service->record($invoice->uuid, (string) Str::uuid(), $this->payload('59.8766'));
        try {
            $service->record($invoice->uuid, (string) Str::uuid(), $this->payload('0.0001'));
            $this->fail('Ruční úhrada nesmí vytvořit přeplatek.');
        } catch (ValidationException) {
        }

        $this->assertSame($first->id, $repeated->id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, InvoicePayment::query()->count());
        $summary = app(InvoicePaymentReader::class)->summary($invoice->fresh());
        $this->assertSame('100.0000', $summary->paidTotal);
        $this->assertSame('0.0000', $summary->remainingTotal);
        $this->assertSame('paid', $summary->status->value);
        $this->assertSame('0.0000', $summary->overpaymentTotal);
        $this->assertSame(2, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.payment_recorded')->count());
        $audit = DB::connection('business_1')->table('audit_logs')->where('auditable_uuid', $first->uuid)->first();
        $auditValues = json_decode((string) $audit->new_values, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('••••3456', $auditValues['reference_masked']);
        $this->assertStringNotContainsString('Citlivá celá poznámka', (string) $audit->new_values);
        Event::assertDispatched(InvoicePaymentChanged::class, 2);
        Event::assertNotDispatched(InvoicePaymentChanged::class, fn (InvoicePaymentChanged $event): bool => in_array('admin.invoice.overpaid', $event->snapshot->notificationIntents, true));
    }

    public function test_partial_and_full_reversals_never_rewrite_original_or_exceed_it(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $service = app(InvoicePaymentService::class);
        $payment = $service->record($invoice->uuid, (string) Str::uuid(), $this->payload('80'));
        $first = $service->reverse($invoice->uuid, $payment->uuid, (string) Str::uuid(), $this->reversal('30'));
        $correlation = (string) Str::uuid();
        $second = $service->reverse($invoice->uuid, $payment->uuid, $correlation, $this->reversal('50'));
        $repeated = $service->reverse($invoice->uuid, $payment->uuid, $correlation, $this->reversal('1'));

        $this->assertSame($second->id, $repeated->id);
        $this->assertSame('80.0000', $payment->fresh()->amount);
        $this->assertSame($payment->id, $first->reverses_payment_id);
        $this->assertSame(3, InvoicePayment::query()->count());
        $this->assertSame('0.0000', app(InvoicePaymentReader::class)->summary($invoice->fresh())->paidTotal);

        try {
            $service->reverse($invoice->uuid, $payment->uuid, (string) Str::uuid(), $this->reversal('0.0001'));
            $this->fail('Původní platbu nelze reverzovat nad její částku.');
        } catch (InvoicePaymentReversalInvalid) {
            $this->assertSame(3, InvoicePayment::query()->count());
            $this->assertSame(2, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.payment_reversed')->count());
            $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.payment_conflict')->count());
        }
    }

    public function test_draft_currency_and_missing_context_fail_closed(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);
        $this->actingAs($admin);
        try {
            app(InvoicePaymentService::class)->record($draft->uuid, (string) Str::uuid(), $this->payload('10'));
            $this->fail('Draft nesmí přijmout platbu.');
        } catch (InvoicePaymentNotAllowed) {
            $this->assertSame(0, InvoicePayment::query()->count());
        }

        app(ActiveBusinessContext::class)->clear();
        $this->expectException(MissingBusinessContext::class);
        InvoicePayment::query()->count();
    }

    public function test_currency_mismatch_and_audit_failure_leave_ledger_empty(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        try {
            app(InvoicePaymentService::class)->record($invoice->uuid, (string) Str::uuid(), $this->payload('10', ['currency' => 'EUR']));
            $this->fail('Měna musí odpovídat faktuře.');
        } catch (ValidationException) {
            $this->assertSame(0, InvoicePayment::query()->count());
        }

        $writer = Mockery::mock(BusinessAuditWriter::class);
        $writer->shouldReceive('write')->andThrow(new \RuntimeException('audit unavailable'));
        app()->instance(BusinessAuditWriter::class, $writer);
        try {
            app(InvoicePaymentService::class)->record($invoice->uuid, (string) Str::uuid(), $this->payload('10'));
            $this->fail('Selhání auditu musí rollbacknout platbu.');
        } catch (\RuntimeException) {
            $this->assertSame(0, InvoicePayment::query()->count());
            $this->assertFalse(DB::connection('central')->getSchemaBuilder()->hasTable('invoice_payments'));
            $this->assertSame('central', DB::getDefaultConnection());
        }
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(string $amount, array $overrides = []): array
    {
        return array_replace([
            'amount' => $amount, 'currency' => 'CZK', 'paid_on' => '2026-08-04',
            'payment_method' => 'bank_transfer', 'reference' => 'REFERENCE-123456',
            'variable_symbol' => '20260001', 'note' => 'Citlivá celá poznámka',
        ], $overrides);
    }

    /** @return array<string, string> */
    private function reversal(string $amount): array
    {
        return ['amount' => $amount, 'reversed_on' => '2026-08-04', 'reason' => 'Oprava chybně evidované úhrady'];
    }
}
