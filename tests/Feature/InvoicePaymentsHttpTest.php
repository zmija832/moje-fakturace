<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Services\Business\InvoiceIssuer;
use App\Services\Business\InvoicePaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoicePaymentsHttpTest extends TestCase
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
        CarbonImmutable::setTestNow();
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_admin_records_payment_and_overdue_disappears_after_full_payment(): void
    {
        CarbonImmutable::setTestNow('2026-08-20 10:00:00');
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->get(route('invoices.show', $invoice->uuid))->assertOk()
            ->assertSee('Platby')->assertSee('Přidat platbu')->assertSee('po splatnosti')
            ->assertDontSee('business_1')->assertDontSee('invoice_id');
        $this->post(route('invoices.payments.store', $invoice->uuid), $this->paymentPayload('40'))
            ->assertRedirect(route('invoices.show', $invoice->uuid))->assertSessionHas('status', 'Platba byla bezpečně zaevidována.');
        $this->get(route('invoices.show', $invoice->uuid))->assertOk()
            ->assertSee('40,00')->assertSee('60,00')->assertSee('Částečně uhrazená')->assertSee('po splatnosti');
        $this->post(route('invoices.payments.store', $invoice->uuid), $this->paymentPayload('60'))
            ->assertSessionHas('status');
        $this->get(route('invoices.show', $invoice->uuid))->assertOk()
            ->assertSee('Uhrazená')->assertSee('100,00')->assertDontSee('po splatnosti');
        $this->get(route('invoices.index', ['payment_status' => 'paid']))->assertOk()->assertSee($invoice->document_number);
        $this->get(route('invoices.index', ['overdue' => 1]))->assertOk()->assertDontSee($invoice->document_number);
        $this->get(route('dashboard'))->assertOk()->assertSee('Úhrady v CZK')->assertSee('Zbývá uhradit');
    }

    public function test_viewer_reads_ledger_but_cannot_record_or_reverse_and_draft_has_no_payment_ui(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        [$viewer] = $this->deliveryMembership('viewer', BusinessConnection::Business1, $business);
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->get(route('invoices.show', $draft->uuid))->assertOk()->assertDontSee('Přidat platbu')->assertDontSee('Neměnná historie přijatých plateb');
        app(ActiveBusinessContext::class)->set($business);
        $invoice = app(InvoiceIssuer::class)->issue($draft->uuid, 1, (string) Str::uuid());
        $payment = app(InvoicePaymentService::class)->record(
            $invoice->uuid, (string) Str::uuid(), $this->paymentPayload('25'),
        );
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($business));

        $this->get(route('invoices.show', $invoice->uuid))->assertOk()->assertSee('Platby')->assertSee('25,00')
            ->assertDontSee('Přidat platbu')->assertDontSee('Stornovat');
        $this->post(route('invoices.payments.store', $invoice->uuid), $this->paymentPayload('10'))->assertForbidden();
        $this->post(route('invoices.payments.reverse', [$invoice->uuid, $payment->uuid]), $this->reversalPayload('10'))->assertForbidden();
        $this->assertSame(1, DB::connection('business_1')->table('invoice_payments')->count());
    }

    public function test_requests_reject_authoritative_fields_and_other_tenant_uuid_is_not_visible(): void
    {
        [$adminOne, $businessOne] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($businessOne);
        [$invoice] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($adminOne)->withSession($this->deliveryBusinessSession($businessOne));
        $this->post(route('invoices.payments.store', $invoice->uuid), $this->paymentPayload('10') + [
            'connection' => 'business_2', 'business_id' => 2, 'paid_total' => '100', 'remaining_total' => '0',
            'payment_status' => 'paid', 'external_id' => 'BANK-1', 'reverses_payment_id' => 1,
        ])->assertSessionHasErrors(['connection', 'business_id', 'paid_total', 'remaining_total', 'payment_status', 'external_id', 'reverses_payment_id']);
        $this->assertSame(0, DB::connection('business_1')->table('invoice_payments')->count());

        $businessTwo = Business::query()->create([
            'uuid' => (string) Str::uuid(), 'display_name' => 'Druhý subjekt', 'registration_number' => '11223344',
            'short_label' => 'S2', 'visual_identifier' => 'briefcase', 'connection_name' => 'business_2', 'is_active' => true, 'sort_order' => 2,
        ]);
        $adminOne->businesses()->attach($businessTwo, ['role' => 'admin']);
        $this->withSession($this->deliveryBusinessSession($businessTwo));
        $this->get(route('invoices.show', $invoice->uuid))->assertNotFound();
        $this->post(route('invoices.payments.store', $invoice->uuid), $this->paymentPayload('10'))->assertNotFound();
        $this->assertSame(0, DB::connection('business_2')->table('invoice_payments')->count());

        foreach (['invoices.payments.store', 'invoices.payments.reverse'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertSame(['POST'], $route->methods());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('business.required', $route->gatherMiddleware());
        }
        $this->assertNull(app('router')->getRoutes()->getByName('invoices.payments.destroy'));
    }

    /** @return array<string, string> */
    private function paymentPayload(string $amount): array
    {
        return [
            'amount' => $amount, 'currency' => 'CZK', 'paid_on' => '2026-08-04', 'payment_method' => 'bank_transfer',
            'reference' => 'Příchozí platba', 'variable_symbol' => '20260001', 'note' => '', 'correlation_uuid' => (string) Str::uuid(),
        ];
    }

    /** @return array<string, string> */
    private function reversalPayload(string $amount): array
    {
        return ['amount' => $amount, 'reversed_on' => '2026-08-04', 'reason' => 'Oprava', 'correlation_uuid' => (string) Str::uuid()];
    }
}
