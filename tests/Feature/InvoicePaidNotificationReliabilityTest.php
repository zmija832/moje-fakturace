<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\Invoices\InvoicePaymentEventSnapshot;
use App\Enums\BusinessConnection;
use App\Mail\AutomationMail;
use App\Models\Business;
use App\Models\Business\Invoice;
use App\Models\Business\InvoicePaidNotification;
use App\Models\Business\InvoicePayment;
use App\Services\Business\AutomationTemplateRenderer;
use App\Services\Business\InvoiceAutomationSettingsService;
use App\Services\Business\InvoicePaidNotificationService;
use App\Services\Business\InvoicePaymentService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\TestCase;

class InvoicePaidNotificationReliabilityTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_each_partial_and_full_payment_creates_one_admin_and_customer_delivery(): void
    {
        Mail::fake();
        [$invoice] = $this->environment(admin: true, customer: true);
        $first = $this->pay($invoice, '40');

        $this->assertSame(3, InvoicePaidNotification::query()->where('triggering_payment_uuid', $first->uuid)->count());
        $partialAdmin = InvoicePaidNotification::query()->where('triggering_payment_uuid', $first->uuid)->where('recipient_type', 'admin_email')->sole();
        $partialCustomer = InvoicePaidNotification::query()->where('triggering_payment_uuid', $first->uuid)->where('recipient_type', 'customer')->sole();
        $this->assertSame('sent', $partialAdmin->status);
        $this->assertSame('sent', $partialCustomer->status);
        $this->assertSame(auth()->user()->email, $partialAdmin->recipient_email);
        $this->assertStringContainsString('Přijatá platba: 40 Kč', $partialAdmin->body_text);
        $this->assertStringContainsString('Celkem uhrazeno: 40 Kč', $partialAdmin->body_text);
        $this->assertStringContainsString('Zbývá: 60 Kč', $partialAdmin->body_text);
        $this->assertStringContainsString('evidujeme platbu ve výši 40 Kč', $partialCustomer->body_text);
        $this->assertStringContainsString('Zbývá uhradit: 60 Kč', $partialCustomer->body_text);

        $second = $this->pay($invoice, '60');
        $this->assertSame(3, InvoicePaidNotification::query()->where('triggering_payment_uuid', $second->uuid)->count());
        $paidAdmin = InvoicePaidNotification::query()->where('triggering_payment_uuid', $second->uuid)->where('recipient_type', 'admin_email')->sole();
        $this->assertStringContainsString('Celkem uhrazeno: 100 Kč', $paidAdmin->body_text);
        $this->assertStringContainsString('Zbývá: 0 Kč', $paidAdmin->body_text);
        $this->assertSame(6, InvoicePaidNotification::query()->count());
        Mail::assertSent(AutomationMail::class, 4);

        app(InvoicePaidNotificationService::class)->handle(new InvoicePaymentEventSnapshot(
            $invoice->uuid, (string) $invoice->document_number, $second->uuid, 'payment', '60.0000', 'CZK',
            '2026-08-19', 'partially_paid', 'paid', '100.0000', '0.0000', ['admin.invoice.paid', 'client.invoice.payment_confirmation'],
        ));
        $this->assertSame(6, InvoicePaidNotification::query()->count());
        Mail::assertSent(AutomationMail::class, 4);
    }

    public function test_paid_transition_creates_internal_admin_notification(): void
    {
        [$invoice] = $this->environment(admin: true, customer: false);
        $this->pay($invoice);

        $notification = InvoicePaidNotification::query()->where('recipient_type', 'admin')->sole();
        $this->assertSame('admin', $notification->recipient_type);
        $this->assertSame('internal', $notification->status);
        $this->assertSame(0, $notification->send_attempts);
        $email = InvoicePaidNotification::query()->where('recipient_type', 'admin_email')->sole();
        $this->assertSame('sent', $email->status);
        $this->assertSame(1, $email->send_attempts);
    }

    public function test_imported_overpayment_uses_the_same_notification_flow_and_formats_overpayment(): void
    {
        Mail::fake();
        [$invoice] = $this->environment(admin: true, customer: true);

        $payment = app(InvoicePaymentService::class)->recordImported(
            $invoice->uuid,
            (string) Str::uuid(),
            'fio:overpayment-notification',
            ['amount' => '110', 'currency' => 'CZK', 'paid_on' => '2026-08-19', 'payment_method' => 'bank_transfer'],
            static function (): void {},
        );

        $admin = InvoicePaidNotification::query()->where('triggering_payment_uuid', $payment->uuid)->where('recipient_type', 'admin_email')->sole();
        $customer = InvoicePaidNotification::query()->where('triggering_payment_uuid', $payment->uuid)->where('recipient_type', 'customer')->sole();
        $this->assertStringContainsString('Přijatá platba: 110 Kč', $admin->body_text);
        $this->assertStringContainsString('Přeplatek: 10 Kč', $admin->body_text);
        $this->assertStringContainsString('přeplatek ve výši 10 Kč', $customer->body_text);
        $this->assertSame('sent', $admin->status);
        $this->assertSame('sent', $customer->status);
        Mail::assertSent(AutomationMail::class, 2);
    }

    public function test_admin_email_recipient_is_resolved_from_the_active_tenant_membership(): void
    {
        Mail::fake();
        [$firstAdmin, $firstBusiness] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($firstBusiness);
        $this->actingAs($firstAdmin);
        [$firstInvoice] = $this->createIssuedInvoice();
        $settings = app(InvoiceAutomationSettingsService::class);
        $settings->save([...$settings->defaults(), 'notify_admin_when_paid' => true, 'notify_customer_when_paid' => false]);
        $this->pay($firstInvoice);

        [$secondAdmin, $secondBusiness] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($secondBusiness);
        $this->actingAs($secondAdmin);
        [$secondInvoice] = $this->createIssuedInvoice();
        $settings->save([...$settings->defaults(), 'notify_admin_when_paid' => true, 'notify_customer_when_paid' => false]);
        $this->pay($secondInvoice);

        Mail::assertSent(AutomationMail::class, fn (AutomationMail $mail): bool => $mail->hasTo($firstAdmin->email));
        Mail::assertSent(AutomationMail::class, fn (AutomationMail $mail): bool => $mail->hasTo($secondAdmin->email));
        $this->assertNotSame($firstAdmin->email, $secondAdmin->email);
    }

    public function test_customer_branch_saves_model_and_sends_valid_email(): void
    {
        Mail::fake();
        [$invoice] = $this->environment(admin: false, customer: true);
        $this->pay($invoice);

        $notification = InvoicePaidNotification::query()->sole();
        $this->assertInstanceOf(InvoicePaidNotification::class, $notification);
        $this->assertSame('customer', $notification->recipient_type);
        $this->assertSame('sent', $notification->status);
        $this->assertSame(1, $notification->send_attempts);
        Mail::assertSent(AutomationMail::class, 1);
    }

    public function test_paid_amount_placeholder_uses_customer_facing_money_format(): void
    {
        [$invoice] = $this->environment(admin: false, customer: false);

        $rendered = app(AutomationTemplateRenderer::class)->paid($invoice, '{amount}', 'Přijato {amount}.', '2026-08-19');

        $this->assertSame('100 Kč', $rendered['subject']);
        $this->assertSame('Přijato 100 Kč.', $rendered['body']);
        $this->assertStringNotContainsString('.0000', $rendered['body']);
    }

    public function test_missing_customer_email_and_smtp_failure_end_as_failed(): void
    {
        [$invoice] = $this->environment(admin: false, customer: false);
        $this->pay($invoice);
        $missing = $this->prepared($invoice, null);

        $service = app(InvoicePaidNotificationService::class);
        $this->assertSame('failed', $service->send($missing)->status);
        $this->assertSame('recipient_missing', $missing->refresh()->failure_code);

        $missing->delete();
        $smtp = $this->prepared($invoice, 'snapshot@example.test');
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP unavailable'));
        $this->assertSame('failed', $service->send($smtp)->status);
        $this->assertSame(1, $smtp->refresh()->send_attempts);
    }

    public function test_automation_retries_failed_customer_notification_while_still_paid(): void
    {
        [$invoice, $business] = $this->environment(admin: false, customer: false);
        $this->pay($invoice);
        $notification = $this->prepared($invoice, 'snapshot@example.test');
        $notification->forceFill(['status' => 'failed'])->save();
        Mail::fake();
        app(ActiveBusinessContext::class)->clear();

        $this->artisan('app:run-invoice-automation', ['--business' => $business->uuid, '--limit' => 10])
            ->assertSuccessful();
        app(ActiveBusinessContext::class)->set($business);

        $this->assertSame('sent', $notification->refresh()->status);
        $this->assertSame(1, $notification->send_attempts);
        Mail::assertSent(AutomationMail::class, 1);
    }

    public function test_failed_confirmation_is_not_retried_after_reversal(): void
    {
        [$invoice, $business] = $this->environment(admin: false, customer: false);
        $payment = $this->pay($invoice);
        $notification = $this->prepared($invoice, 'snapshot@example.test');
        $notification->forceFill(['status' => 'failed'])->save();
        app(InvoicePaymentService::class)->reverse($invoice->uuid, $payment->uuid, (string) Str::uuid(), [
            'amount' => '100',
            'reversed_on' => '2026-08-19',
            'reason' => 'Oprava platby',
        ]);
        Mail::fake();
        app(ActiveBusinessContext::class)->clear();

        $this->artisan('app:run-invoice-automation', ['--business' => $business->uuid, '--limit' => 10])
            ->assertSuccessful();
        app(ActiveBusinessContext::class)->set($business);

        $this->assertSame('failed', $notification->refresh()->status);
        $this->assertSame(0, $notification->send_attempts);
        Mail::assertNothingSent();
    }

    public function test_sent_and_active_claim_are_not_sent_twice(): void
    {
        [$invoice] = $this->environment(admin: false, customer: false);
        $this->pay($invoice);
        $notification = $this->prepared($invoice, 'snapshot@example.test');
        $service = app(InvoicePaidNotificationService::class);

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturnUsing(function () use ($notification, $service): void {
            $overlapping = $service->send($notification);
            $this->assertSame('sending', $overlapping->status);
            $this->assertSame(1, $overlapping->send_attempts);
        });
        $this->assertSame('sent', $service->send($notification)->status);
        $this->assertSame('sent', $service->send($notification)->status);
        $this->assertSame(1, $notification->refresh()->send_attempts);
    }

    public function test_stale_customer_delivery_claim_can_be_retried(): void
    {
        Mail::fake();
        [$invoice] = $this->environment(admin: false, customer: false);
        $this->pay($invoice);
        $notification = $this->prepared($invoice, 'snapshot@example.test');
        $notification->forceFill([
            'status' => 'sending',
            'claim_token' => (string) Str::uuid(),
            'claimed_at' => now()->subMinutes(16),
            'send_attempts' => 1,
        ])->save();

        $result = app(InvoicePaidNotificationService::class)->retryDue();

        $this->assertSame(['processed' => 1, 'failed' => 0], $result);
        $this->assertSame('sent', $notification->refresh()->status);
        $this->assertSame(2, $notification->send_attempts);
        Mail::assertSent(AutomationMail::class, 1);
    }

    public function test_disabled_customer_setting_is_not_applied_retroactively(): void
    {
        Mail::fake();
        [$invoice, $business] = $this->environment(admin: false, customer: false);
        $this->pay($invoice);
        $this->assertSame(0, InvoicePaidNotification::query()->count());

        $settings = app(InvoiceAutomationSettingsService::class);
        $settings->save([...$settings->defaults(), 'notify_admin_when_paid' => false, 'notify_customer_when_paid' => true]);
        app(ActiveBusinessContext::class)->clear();
        $this->artisan('app:run-invoice-automation', ['--business' => $business->uuid])->assertSuccessful();
        app(ActiveBusinessContext::class)->set($business);

        $this->assertSame(0, InvoicePaidNotification::query()->count());
        Mail::assertNothingSent();
    }

    public function test_retry_is_tenant_isolated(): void
    {
        [$invoice, $firstBusiness] = $this->environment(admin: false, customer: false);
        $this->pay($invoice);
        $notification = $this->prepared($invoice, 'snapshot@example.test');
        $notification->forceFill(['status' => 'failed'])->save();
        [, $secondBusiness] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($secondBusiness);

        $this->assertSame(['processed' => 0, 'failed' => 0], app(InvoicePaidNotificationService::class)->retryDue());
        app(ActiveBusinessContext::class)->set($firstBusiness);
        $this->assertSame('failed', $notification->refresh()->status);
        $this->assertSame(0, $notification->send_attempts);
    }

    /** @return array{Invoice,Business} */
    private function environment(bool $admin, bool $customer): array
    {
        [$user, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        $this->actingAs($user);
        [$invoice] = $this->createIssuedInvoice();
        $settings = app(InvoiceAutomationSettingsService::class);
        $settings->save([
            ...$settings->defaults(),
            'notify_admin_when_paid' => $admin,
            'notify_customer_when_paid' => $customer,
        ]);

        return [$invoice, $business];
    }

    private function pay(Invoice $invoice, string $amount = '100'): InvoicePayment
    {
        return app(InvoicePaymentService::class)->record($invoice->uuid, (string) Str::uuid(), [
            'amount' => $amount,
            'currency' => 'CZK',
            'paid_on' => '2026-08-19',
            'payment_method' => 'bank_transfer',
        ]);
    }

    private function prepared(Invoice $invoice, ?string $recipient): InvoicePaidNotification
    {
        $notification = new InvoicePaidNotification;
        $notification->forceFill([
            'invoice_id' => $invoice->id,
            'triggering_payment_uuid' => (string) Str::uuid(),
            'recipient_type' => 'customer',
            'recipient_email' => $recipient,
            'subject' => 'Potvrzení úhrady',
            'body_text' => 'Děkujeme.',
            'status' => 'prepared',
            'correlation_uuid' => (string) Str::uuid(),
        ]);
        $notification->save();

        return $notification;
    }
}
