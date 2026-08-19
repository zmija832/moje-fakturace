<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Mail\AutomationMail;
use App\Models\Business;
use App\Models\Business\Invoice;
use App\Models\Business\InvoicePaidNotification;
use App\Models\Business\InvoicePayment;
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

    public function test_partial_payment_creates_nothing_and_transition_to_paid_creates_admin_event(): void
    {
        [$invoice] = $this->environment(admin: true, customer: false);
        $this->pay($invoice, '50');

        $this->assertSame(0, InvoicePaidNotification::query()->count());
        $this->pay($invoice, '50');
        $this->assertSame('internal', InvoicePaidNotification::query()->sole()->status);
    }

    public function test_paid_transition_creates_internal_admin_notification(): void
    {
        [$invoice] = $this->environment(admin: true, customer: false);
        $this->pay($invoice);

        $notification = InvoicePaidNotification::query()->sole();
        $this->assertSame('admin', $notification->recipient_type);
        $this->assertSame('internal', $notification->status);
        $this->assertSame(0, $notification->send_attempts);
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
