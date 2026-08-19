<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\Exceptions\MissingBusinessContext;
use App\Enums\BusinessConnection;
use App\Mail\AutomationMail;
use App\Models\Business\Invoice;
use App\Models\Business\InvoicePaidNotification;
use App\Models\Business\InvoiceReminder;
use App\Models\Business\RecurringInvoiceRun;
use App\Models\Business\RecurringInvoiceTemplate;
use App\Services\Business\DashboardOverviewService;
use App\Services\Business\InvoiceAutomationSettingsService;
use App\Services\Business\InvoiceDraftService;
use App\Services\Business\InvoiceIssuer;
use App\Services\Business\InvoicePaymentService;
use App\Services\Business\InvoiceReminderService;
use App\Services\Business\RecurringInvoiceRunner;
use App\Services\Business\RecurringInvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\TestCase;

class InvoiceAutomationTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_admin_can_manage_template_viewer_cannot_and_tenant_context_is_required(): void
    {
        [$admin,$business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [, $client,$account] = $this->createIssuedInvoice(false);
        $this->actingAs($admin);
        $template = app(RecurringInvoiceService::class)->create($this->templatePayload($client->uuid, $account->uuid));
        $this->assertDatabaseHas('recurring_invoice_templates', ['uuid' => $template->uuid, 'is_active' => 1], 'business_1');
        app(RecurringInvoiceService::class)->setActive($template, false);
        app(RecurringInvoiceService::class)->setActive($template->refresh(), true);
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'recurring_invoice.created')->count());
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'recurring_invoice.paused')->count());
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'recurring_invoice.resumed')->count());
        $this->withSession($this->deliveryBusinessSession($business))->get(route('recurring.show', $template->uuid))->assertOk();
        [$viewer] = $this->deliveryMembership('viewer', business: $business);
        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($business))->get(route('recurring.create'))->assertForbidden();
        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($business))->get(route('automation-settings.edit'))->assertForbidden();
        app(ActiveBusinessContext::class)->clear();
        $this->expectException(MissingBusinessContext::class);
        RecurringInvoiceTemplate::query()->count();
    }

    public function test_due_run_is_idempotent_and_preserves_month_end_anchor(): void
    {
        [$admin,$business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [, $client,$account] = $this->createIssuedInvoice(false);
        $this->actingAs($admin);
        $template = app(RecurringInvoiceService::class)->create($this->templatePayload($client->uuid, $account->uuid, ['next_run_on' => '2027-01-31']));
        $runner = app(RecurringInvoiceRunner::class);
        $first = $runner->run($template);
        $second = $runner->run($template->refresh(), CarbonImmutable::parse('2027-01-31'));
        $this->assertSame($first->id, $second->id);
        $this->assertSame('draft_created', $first->refresh()->status);
        $this->assertSame(1, RecurringInvoiceRun::query()->count());
        $this->assertSame('2027-02-28', $template->refresh()->next_run_on->format('Y-m-d'));
        $this->assertNotNull($first->invoice_uuid);
    }

    public function test_reminder_uses_ledger_skips_paid_and_is_exactly_once(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 09:00:00');
        [$admin,$business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $service = app(InvoiceAutomationSettingsService::class);
        $service->save([...$service->defaults(), 'reminders_enabled' => true]);
        $runner = app(InvoiceReminderService::class);
        $one = $runner->runDue(CarbonImmutable::today());
        $two = $runner->runDue(CarbonImmutable::today());
        $this->assertSame(1, $one['processed']);
        $this->assertSame(0, $two['processed']);
        $this->assertSame(1, InvoiceReminder::query()->count());
        $this->assertSame('prepared', InvoiceReminder::query()->first()->status);
        $this->withSession($this->deliveryBusinessSession($business))->get(route('invoices.reminders.form', $invoice->uuid))->assertOk()->assertSee('Připomínka splatnosti');
        $this->withSession($this->deliveryBusinessSession($business))->patch(route('invoices.reminders.toggle', $invoice->uuid), ['disabled' => true])->assertRedirect();
        $this->assertTrue((bool) $invoice->reminderOverride()->value('disabled'));
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.reminder_preference_changed')->count());
        app(InvoicePaymentService::class)->record($invoice->uuid, (string) Str::uuid(), ['amount' => '100', 'currency' => 'CZK', 'paid_on' => '2026-08-17', 'payment_method' => 'bank_transfer']);
        $this->assertSame(0, $runner->runDue(CarbonImmutable::today()->addDays(6))['processed']);
    }

    public function test_auto_issue_uses_existing_issuer_allocator_and_pdf_generator(): void
    {
        Storage::fake('invoice_documents');
        [$admin,$business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [, $client,$account] = $this->createIssuedInvoice(false);
        $this->actingAs($admin);
        $template = app(RecurringInvoiceService::class)->create($this->templatePayload($client->uuid, $account->uuid, ['mode' => 'auto_issue']));
        $run = app(RecurringInvoiceRunner::class)->run($template);
        $invoice = Invoice::query()->where('uuid', $run->invoice_uuid)->firstOrFail();
        $this->assertSame('issued', $run->refresh()->status);
        $this->assertSame('issued', $invoice->status->value);
        $this->assertNotNull($invoice->document_number);
        $this->assertSame(1, $invoice->documents()->count());
        $this->assertSame($invoice->current_revision_id, $invoice->issued_revision_id);
    }

    public function test_full_payment_creates_configured_notifications_only_once_and_reversal_does_not_repeat(): void
    {
        Mail::fake();
        [$admin,$business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $settings = app(InvoiceAutomationSettingsService::class);
        $settings->save([...$settings->defaults(), 'notify_admin_when_paid' => true, 'notify_customer_when_paid' => true]);
        $payments = app(InvoicePaymentService::class);
        $payment = $payments->record($invoice->uuid, (string) Str::uuid(), ['amount' => '100', 'currency' => 'CZK', 'paid_on' => '2026-08-18', 'payment_method' => 'bank_transfer']);
        $this->assertSame(2, InvoicePaidNotification::query()->count());
        $this->assertSame(['admin', 'customer'], InvoicePaidNotification::query()->orderBy('recipient_type')->pluck('recipient_type')->all());
        Mail::assertSent(AutomationMail::class, 1);
        $payments->reverse($invoice->uuid, $payment->uuid, (string) Str::uuid(), ['amount' => '100', 'reversed_on' => '2026-08-18', 'reason' => 'Oprava']);
        $payments->record($invoice->uuid, (string) Str::uuid(), ['amount' => '100', 'currency' => 'CZK', 'paid_on' => '2026-08-18', 'payment_method' => 'bank_transfer']);
        $this->assertSame(2, InvoicePaidNotification::query()->count());
        Mail::assertSent(AutomationMail::class, 1);
    }

    public function test_automation_command_visits_both_tenants_and_is_safe_no_op(): void
    {
        [, $first] = $this->deliveryMembership();
        [, $second] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->clear();
        $this->artisan('app:run-invoice-automation', ['--limit' => 5])->expectsOutputToContain($first->display_name)->expectsOutputToContain($second->display_name)->assertSuccessful();
        $this->assertNull(app(ActiveBusinessContext::class)->business());
        $this->assertSame(0, DB::connection('business_1')->table('recurring_invoice_runs')->count());
    }

    public function test_dashboard_keeps_currencies_separate(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [, $client] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $draft = app(InvoiceDraftService::class)->create([
            'customer_uuid' => $client->uuid,
            'bank_account_uuid' => null,
            'currency' => 'EUR',
            'issued_on' => '2026-08-05',
            'taxable_supply_on' => '2026-08-05',
            'due_on' => '2026-08-15',
            'payment_method' => 'cash',
            'invoice_discount_type' => 'none',
            'items' => [[
                'position' => 1, 'description' => 'EUR služba', 'quantity' => '1',
                'unit' => 'ks', 'unit_price' => '50', 'discount_type' => 'none',
            ]],
        ]);
        app(InvoiceIssuer::class)->issue($draft->uuid, $draft->version, (string) Str::uuid());

        $currencies = app(DashboardOverviewService::class)->overview()['currencies'];
        $this->assertSame(['CZK', 'EUR'], $currencies->pluck('currency')->sort()->values()->all());
        $this->assertSame('100.0000', (string) $currencies->firstWhere('currency', 'CZK')->outstanding_total);
        $this->assertSame('50.0000', (string) $currencies->firstWhere('currency', 'EUR')->outstanding_total);
    }

    public function test_automation_command_processes_pending_recurring_for_selected_tenant(): void
    {
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [, $client, $account] = $this->createIssuedInvoice(false);
        app(RecurringInvoiceService::class)->create($this->templatePayload($client->uuid, $account->uuid, ['next_run_on' => today()->format('Y-m-d')]));
        app(ActiveBusinessContext::class)->clear();

        $this->artisan('app:run-invoice-automation', ['--business' => $business->uuid, '--limit' => 5])->assertSuccessful();
        $this->assertSame(1, DB::connection('business_1')->table('recurring_invoice_runs')->count());
        $this->assertSame('draft_created', DB::connection('business_1')->table('recurring_invoice_runs')->value('status'));
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function templatePayload(string $client, string $account, array $overrides = []): array
    {
        return [...['name' => 'Měsíční servis', 'client_uuid' => $client, 'bank_account_uuid' => $account, 'currency' => 'CZK', 'payment_method' => 'bank_transfer', 'due_days' => 14, 'interval_months' => 1, 'next_run_on' => '2026-09-01', 'mode' => 'draft', 'auto_send' => false, 'note' => 'Pravidelná služba', 'invoice_discount_type' => 'none', 'invoice_discount_value' => null, 'items' => [['description' => 'Servis', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '100', 'discount_type' => 'none', 'discount_value' => null]]], ...$overrides];
    }
}
