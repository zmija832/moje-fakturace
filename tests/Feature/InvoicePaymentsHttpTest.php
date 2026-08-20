<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Events\InvoicePaymentChanged;
use App\Models\Business;
use App\Models\Business\InvoicePayment;
use App\Services\Business\InvoiceIssuer;
use App\Services\Business\InvoicePaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use RuntimeException;
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
            ->assertSee('Platby')->assertSee('Zaznamenat úhradu')->assertSee('Po splatnosti')
            ->assertSee('id="payment-amount" name="amount" value="100"', false)
            ->assertDontSee('value="100.0000"', false)
            ->assertSee('Odeslat klientovi')->assertSee('Tiskový náhled')->assertSee('Duplikovat fakturu')
            ->assertSee('bg-amber-100', false)->assertSee('bg-red-100', false)
            ->assertDontSee('business_1')->assertDontSee('invoice_id');
        $this->post(route('invoices.payments.store', $invoice->uuid), $this->paymentPayload('40'))
            ->assertRedirect(route('invoices.show', $invoice->uuid))->assertSessionHas('status', 'Platba byla bezpečně zaevidována.');
        $this->get(route('invoices.show', $invoice->uuid))->assertOk()
            ->assertSee('40 Kč')->assertSee('60 Kč')->assertSee('Částečně uhrazená')->assertSee('Po splatnosti')
            ->assertSee('bg-blue-100', false);
        $this->post(route('invoices.payments.store', $invoice->uuid), $this->paymentPayload('60'))
            ->assertSessionHas('status');
        $this->get(route('invoices.show', $invoice->uuid))->assertOk()
            ->assertSee('Uhrazená')->assertSee('100 Kč')->assertDontSee('Po splatnosti')
            ->assertSee('bg-emerald-100', false)->assertDontSee('Zaznamenat úhradu');
        $this->get(route('invoices.index', ['payment_status' => 'paid']))->assertOk()->assertSee($invoice->document_number);
        $this->get(route('invoices.index', ['overdue' => 1]))->assertOk()->assertDontSee($invoice->document_number);
        $this->get(route('dashboard'))->assertOk()->assertSee('Úhrady v Kč')->assertSee('Zbývá uhradit');
    }

    public function test_viewer_reads_ledger_but_cannot_record_or_reverse_and_draft_has_no_payment_ui(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        [$viewer] = $this->deliveryMembership('viewer', BusinessConnection::Business1, $business);
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->get(route('invoices.show', $draft->uuid))->assertOk()->assertDontSee('Zaznamenat úhradu')->assertDontSee('Neměnná historie přijatých plateb');
        app(ActiveBusinessContext::class)->set($business);
        $invoice = app(InvoiceIssuer::class)->issue($draft->uuid, 1, (string) Str::uuid());
        $payment = app(InvoicePaymentService::class)->record(
            $invoice->uuid, (string) Str::uuid(), $this->paymentPayload('25'),
        );
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($business));

        $this->get(route('invoices.show', $invoice->uuid))->assertOk()->assertSee('Platby')->assertSee('25 Kč')
            ->assertDontSee('Zaznamenat úhradu')->assertDontSee('Stornovat');
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

        $this->post(route('invoices.payments.store', $invoice->uuid), $this->paymentPayload('101'))
            ->assertSessionHasErrors('amount');
        $this->post(route('invoices.payments.store', $invoice->uuid), $this->paymentPayload('0'))
            ->assertSessionHasErrors('amount');
        $this->post(route('invoices.payments.store', $invoice->uuid), $this->paymentPayload('-1'))
            ->assertSessionHasErrors('amount');
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

    public function test_real_payment_form_payload_redirects_and_renders_after_partial_and_full_payment(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $html = $this->get(route('invoices.show', $invoice->uuid))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="invoice-payment-entry"[\s\S]+?name="correlation_uuid" value="([^"]+)"/', $html);
        preg_match('/id="invoice-payment-entry"[\s\S]+?name="correlation_uuid" value="([^"]+)"/', $html, $matches);
        $payload = [
            'amount' => '40,00', 'currency' => 'CZK', 'paid_on' => '2026-08-14',
            'payment_method' => 'bank_transfer', 'note' => 'Částečná úhrada', 'correlation_uuid' => $matches[1],
        ];

        $this->followingRedirects()->post(route('invoices.payments.store', $invoice->uuid), $payload)
            ->assertOk()->assertSee('Částečně uhrazená')->assertSee('60 Kč');
        $this->followingRedirects()->post(route('invoices.payments.store', $invoice->uuid), [
            ...$payload, 'amount' => '60', 'correlation_uuid' => (string) Str::uuid(),
        ])->assertOk()->assertSee('Uhrazená')->assertDontSee('Zaznamenat úhradu');
        $this->assertSame(2, InvoicePayment::query()->count());
    }

    public function test_post_commit_notification_failure_does_not_turn_payment_into_http_500(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        Event::listen(InvoicePaymentChanged::class, fn (): never => throw new RuntimeException('Simulované selhání následné integrace'));

        $this->post(route('invoices.payments.store', $invoice->uuid), $this->paymentPayload('25'))
            ->assertRedirect(route('invoices.show', $invoice->uuid))->assertSessionHas('status');
        $this->assertSame(1, InvoicePayment::query()->count());
        $this->assertSame('25.0000', InvoicePayment::query()->sole()->amount);
    }

    public function test_http_500_during_redirect_render_does_not_duplicate_committed_payment_on_retry(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $payload = $this->paymentPayload('30');
        $failRender = true;
        View::composer('business.invoices.show', function () use (&$failRender): void {
            if ($failRender) {
                throw new RuntimeException('Simulovaná chyba následného renderu');
            }
        });

        $this->withExceptionHandling();
        $this->followingRedirects()->post(route('invoices.payments.store', $invoice->uuid), $payload)->assertServerError();
        $this->assertSame(1, InvoicePayment::query()->count());
        $this->assertSame('30.0000', InvoicePayment::query()->sole()->amount);

        $failRender = false;
        $this->followingRedirects()->post(route('invoices.payments.store', $invoice->uuid), $payload)
            ->assertOk()->assertSee('Částečně uhrazená');
        $this->assertSame(1, InvoicePayment::query()->count());
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
