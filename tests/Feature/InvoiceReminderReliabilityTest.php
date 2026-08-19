<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\InvoiceReminderOrigin;
use App\Mail\AutomationMail;
use App\Models\Business;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceReminder;
use App\Models\User;
use App\Services\Business\InvoiceArchiveService;
use App\Services\Business\InvoiceAutomationSettingsService;
use App\Services\Business\InvoiceCancellationService;
use App\Services\Business\InvoicePaymentService;
use App\Services\Business\InvoiceReminderPreferenceService;
use App\Services\Business\InvoiceReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\TestCase;

class InvoiceReminderReliabilityTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_missed_seventh_day_creates_second_level_with_original_planned_date(): void
    {
        [$invoice, $service] = $this->environment('prepare');
        $service->prepare($invoice, 1, CarbonImmutable::parse('2026-08-17'), false);

        $result = $service->runDue(CarbonImmutable::parse('2026-08-24'));

        $this->assertSame(1, $result['processed']);
        $this->assertSame(2, InvoiceReminder::query()->count());
        $second = InvoiceReminder::query()->where('level', 2)->firstOrFail();
        $this->assertSame('prepared', $second->status);
        $this->assertSame('2026-08-23', $second->scheduled_on->format('Y-m-d'));
    }

    public function test_missed_levels_are_caught_up_sequentially_without_duplicates(): void
    {
        [, $service] = $this->environment('prepare');

        $this->assertSame(1, $service->runDue(CarbonImmutable::parse('2026-08-24'))['processed']);
        $this->assertSame([1], InvoiceReminder::query()->pluck('level')->all());
        $this->assertSame(1, $service->runDue(CarbonImmutable::parse('2026-08-24'))['processed']);
        $this->assertSame([1, 2], InvoiceReminder::query()->orderBy('level')->pluck('level')->all());
        $this->assertSame(0, $service->runDue(CarbonImmutable::parse('2026-08-24'))['processed']);
        $this->assertSame(2, InvoiceReminder::query()->count());
        $this->assertSame('2026-08-17', InvoiceReminder::query()->where('level', 1)->firstOrFail()->scheduled_on->format('Y-m-d'));
        $this->assertSame('2026-08-23', InvoiceReminder::query()->where('level', 2)->firstOrFail()->scheduled_on->format('Y-m-d'));
    }

    public function test_failed_reminder_is_retried_in_send_mode_and_sent_is_never_retried(): void
    {
        Mail::fake();
        [$invoice, $service] = $this->environment('send', secondDay: null);
        $reminder = $service->prepare($invoice, 1, CarbonImmutable::parse('2026-08-17'), false);
        $reminder->forceFill(['status' => 'failed', 'failure_code' => 'TransportException'])->save();

        $this->assertSame(1, $service->runDue(CarbonImmutable::parse('2026-08-24'))['processed']);
        $this->assertSame('sent', $reminder->refresh()->status);
        $this->assertSame(1, $reminder->send_attempts);
        Mail::assertSent(AutomationMail::class, 1);

        $this->assertSame(0, $service->runDue(CarbonImmutable::parse('2026-08-25'))['processed']);
        $this->assertSame(1, $reminder->refresh()->send_attempts);
        Mail::assertSent(AutomationMail::class, 1);
    }

    public function test_prepared_reminder_in_prepare_mode_is_not_sent(): void
    {
        Mail::fake();
        [$invoice, $service] = $this->environment('prepare', secondDay: null);
        $reminder = $service->prepare($invoice, 1, CarbonImmutable::parse('2026-08-17'), false);

        $this->assertSame(0, $service->runDue(CarbonImmutable::parse('2026-08-24'))['processed']);
        $this->assertSame('prepared', $reminder->refresh()->status);
        $this->assertSame(0, $reminder->send_attempts);
        Mail::assertNothingSent();
    }

    public function test_second_overlapping_send_cannot_claim_an_actively_claimed_reminder(): void
    {
        [$invoice, $service] = $this->environment('send', secondDay: null);
        $reminder = $service->prepare($invoice, 1, CarbonImmutable::parse('2026-08-17'), false);

        Mail::shouldReceive('to')->once()->with($reminder->recipient_email)->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturnUsing(function () use ($reminder, $service): void {
            $overlapping = $service->send($reminder, InvoiceReminderOrigin::Manual);
            $this->assertSame('sending', $overlapping->status);
            $this->assertSame(1, $overlapping->send_attempts);
        });

        $sent = $service->send($reminder, InvoiceReminderOrigin::Manual);

        $this->assertSame('sent', $sent->status);
        $this->assertSame(1, $sent->send_attempts);
        $this->assertNull($sent->claim_token);
        $this->assertNull($sent->claimed_at);
    }

    public function test_stale_claim_can_be_taken_over(): void
    {
        Mail::fake();
        [$invoice, $service] = $this->environment('send', secondDay: null);
        $reminder = $service->prepare($invoice, 1, CarbonImmutable::parse('2026-08-17'), false);
        $reminder->forceFill([
            'status' => 'sending',
            'claim_token' => (string) Str::uuid(),
            'claimed_at' => now()->subMinutes(16),
            'send_attempts' => 1,
        ])->save();

        $sent = $service->send($reminder);

        $this->assertSame('sent', $sent->status);
        $this->assertSame(2, $sent->send_attempts);
        Mail::assertSent(AutomationMail::class, 1);
    }

    public function test_paid_invoice_does_not_retry_failed_reminder(): void
    {
        Mail::fake();
        [$invoice, $service] = $this->environment('send', secondDay: null, paidNotifications: false);
        $reminder = $service->prepare($invoice, 1, CarbonImmutable::parse('2026-08-17'), false);
        $reminder->forceFill(['status' => 'failed'])->save();
        app(InvoicePaymentService::class)->record($invoice->uuid, (string) Str::uuid(), [
            'amount' => '100',
            'currency' => 'CZK',
            'paid_on' => '2026-08-24',
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertSame(0, $service->runDue(CarbonImmutable::parse('2026-08-24'))['processed']);
        $this->assertSame('failed', $reminder->refresh()->status);
        $this->assertSame(0, $reminder->send_attempts);
        Mail::assertNothingSent();
    }

    public function test_disabled_automatic_reminders_do_not_block_admin_manual_send(): void
    {
        Mail::fake();
        [$invoice, $service, , $business] = $this->environment('send', secondDay: null);
        app(InvoiceReminderPreferenceService::class)->set($invoice, true, 'test');

        $this->assertSame(0, $service->runDue(CarbonImmutable::parse('2026-08-24'))['processed']);
        $this->assertSame(0, InvoiceReminder::query()->count());
        $this->withSession($this->deliveryBusinessSession($business))
            ->get(route('invoices.reminders.form', $invoice->uuid))
            ->assertOk();
        $this->withSession($this->deliveryBusinessSession($business))
            ->post(route('invoices.reminders.send', $invoice->uuid), ['level' => 1])
            ->assertRedirect(route('invoices.show', $invoice->uuid));

        $reminder = InvoiceReminder::query()->firstOrFail();
        $this->assertSame('sent', $reminder->status);
        $this->assertSame(1, $reminder->send_attempts);
        Mail::assertSent(AutomationMail::class, 1);

        $this->withSession($this->deliveryBusinessSession($business))
            ->post(route('invoices.reminders.send', $invoice->uuid), ['level' => 1])
            ->assertRedirect(route('invoices.show', $invoice->uuid));
        $this->assertSame(1, $reminder->refresh()->send_attempts);
        Mail::assertSent(AutomationMail::class, 1);
    }

    public function test_archived_invoice_manual_post_is_rejected_without_record_or_mail(): void
    {
        Mail::fake();
        [$invoice, , , $business] = $this->environment('send');
        app(InvoiceArchiveService::class)->archive($invoice->uuid);

        $this->withSession($this->deliveryBusinessSession($business))
            ->post(route('invoices.reminders.send', $invoice->uuid), ['level' => 1])
            ->assertForbidden();
        $this->assertSame(0, InvoiceReminder::query()->count());
        Mail::assertNothingSent();
    }

    public function test_paid_invoice_manual_post_is_unprocessable_without_record_or_mail(): void
    {
        [$invoice, , , $business] = $this->environment('send', paidNotifications: false);
        app(InvoicePaymentService::class)->record($invoice->uuid, (string) Str::uuid(), [
            'amount' => '100',
            'currency' => 'CZK',
            'paid_on' => '2026-08-24',
            'payment_method' => 'bank_transfer',
        ]);
        Mail::fake();

        $response = $this->withSession($this->deliveryBusinessSession($business))
            ->post(route('invoices.reminders.send', $invoice->uuid), ['level' => 1]);
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $this->assertSame(0, InvoiceReminder::query()->count());
        Mail::assertNothingSent();
    }

    public function test_cancelled_invoice_manual_post_is_rejected_without_record_or_mail(): void
    {
        Mail::fake();
        [$invoice, , , $business] = $this->environment('send');
        app(InvoiceCancellationService::class)->cancel(
            $invoice->uuid,
            $invoice->version,
            (string) Str::uuid(),
            'Test ruční upomínky',
        );

        $this->withSession($this->deliveryBusinessSession($business))
            ->post(route('invoices.reminders.send', $invoice->uuid), ['level' => 1])
            ->assertNotFound();
        $this->assertSame(0, InvoiceReminder::query()->count());
        Mail::assertNothingSent();
    }

    public function test_viewer_cannot_manually_send_reminder(): void
    {
        Mail::fake();
        [$invoice, , , $business] = $this->environment('send');
        [$viewer] = $this->deliveryMembership('viewer', business: $business);
        $this->actingAs($viewer);

        $this->withSession($this->deliveryBusinessSession($business))
            ->post(route('invoices.reminders.send', $invoice->uuid), ['level' => 1])
            ->assertForbidden();
        $this->assertSame(0, InvoiceReminder::query()->count());
        Mail::assertNothingSent();
    }

    #[DataProvider('ineligibleStates')]
    public function test_cancelled_archived_and_disabled_invoices_do_not_retry_failed_reminders(string $state): void
    {
        $this->assertIneligibleStateDoesNotRetry($state);
    }

    /** @return array<string,array{string}> */
    public static function ineligibleStates(): array
    {
        return [
            'cancelled' => ['cancelled'],
            'archived' => ['archived'],
            'disabled' => ['disabled'],
        ];
    }

    /** @return array{Invoice,InvoiceReminderService,User,Business} */
    private function environment(string $mode, ?int $secondDay = 7, bool $paidNotifications = true): array
    {
        CarbonImmutable::setTestNow('2026-08-24 09:00:00');
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        $this->actingAs($admin);
        [$invoice] = $this->createIssuedInvoice();
        $settings = app(InvoiceAutomationSettingsService::class);
        $settings->save([
            ...$settings->defaults(),
            'reminders_enabled' => true,
            'reminder_mode' => $mode,
            'reminder_day_2' => $secondDay,
            'reminder_day_3' => null,
            'notify_admin_when_paid' => $paidNotifications,
            'notify_customer_when_paid' => false,
        ]);

        return [$invoice, app(InvoiceReminderService::class), $admin, $business];
    }

    private function assertIneligibleStateDoesNotRetry(string $state): void
    {
        Mail::fake();
        [$invoice, $service] = $this->environment('send', secondDay: null);
        $reminder = $service->prepare($invoice, 1, CarbonImmutable::parse('2026-08-17'), false);
        $reminder->forceFill(['status' => 'failed'])->save();

        match ($state) {
            'cancelled' => app(InvoiceCancellationService::class)->cancel(
                $invoice->uuid,
                $invoice->version,
                (string) Str::uuid(),
                'Test automatických upomínek',
            ),
            'archived' => app(InvoiceArchiveService::class)->archive($invoice->uuid),
            'disabled' => app(InvoiceReminderPreferenceService::class)->set($invoice, true, 'test'),
        };

        $this->assertSame(0, $service->runDue(CarbonImmutable::parse('2026-08-24'))['processed']);
        $this->assertSame('failed', $reminder->refresh()->status);
        $this->assertSame(0, $reminder->send_attempts);
        Mail::assertNothingSent();

    }
}
