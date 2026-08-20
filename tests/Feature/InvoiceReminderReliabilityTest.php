<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\InvoiceReminderOrigin;
use App\Mail\AutomationMail;
use App\Models\Business;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceDocument;
use App\Models\Business\InvoicePublicLink;
use App\Models\Business\InvoiceReminder;
use App\Models\User;
use App\Services\Business\BusinessDate;
use App\Services\Business\InvoiceArchiveService;
use App\Services\Business\InvoiceAutomationSettingsService;
use App\Services\Business\InvoiceCancellationService;
use App\Services\Business\InvoicePaymentService;
use App\Services\Business\InvoicePdfGenerator;
use App\Services\Business\InvoicePublicLinkService;
use App\Services\Business\InvoiceReminderPreferenceService;
use App\Services\Business\InvoiceReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\TestCase;

class InvoiceReminderReliabilityTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(InvoicePdfGenerator::DISK);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_prague_business_date_creates_first_reminder_one_calendar_day_overdue(): void
    {
        config(['app.timezone' => 'UTC']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-18 22:30:00', 'UTC'));
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        $this->actingAs($admin);
        $this->createIssuedInvoice(true, '2026-08-18');
        $settings = app(InvoiceAutomationSettingsService::class);
        $settings->save([
            ...$settings->defaults(),
            'reminders_enabled' => true,
            'reminder_mode' => 'prepare',
            'reminder_day_1' => 1,
            'reminder_day_2' => null,
            'reminder_day_3' => null,
        ]);

        $today = app(BusinessDate::class)->today();
        $result = app(InvoiceReminderService::class)->runDue($today);

        $this->assertSame('2026-08-19', $today->format('Y-m-d'));
        $this->assertSame(['processed' => 1, 'failed' => 0], $result);
        $reminder = InvoiceReminder::query()->sole();
        $this->assertSame(1, $reminder->level);
        $this->assertSame('prepared', $reminder->status);
        $this->assertSame('2026-08-19', $reminder->scheduled_on->format('Y-m-d'));
        $this->assertStringContainsString('100 Kč', $reminder->body_text);
        $this->assertStringNotContainsString('100.0000', $reminder->body_text);
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

        auth()->logout();

        $this->assertSame(1, $service->runDue(CarbonImmutable::parse('2026-08-24'))['processed']);
        $this->assertSame('sent', $reminder->refresh()->status);
        $this->assertSame(1, $reminder->send_attempts);
        Mail::assertSent(AutomationMail::class, 1);
        $audit = DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.reminder_sent')->sole();
        $values = json_decode($audit->new_values, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('invoice', $audit->auditable_type);
        $this->assertSame($invoice->uuid, $audit->auditable_uuid);
        $this->assertSame($reminder->uuid, $values['reminder_uuid']);
        $this->assertSame('automatic', $values['origin']);
        $this->assertNull($audit->actor_user_uuid);

        $this->assertSame(0, $service->runDue(CarbonImmutable::parse('2026-08-25'))['processed']);
        $this->assertSame(1, $reminder->refresh()->send_attempts);
        Mail::assertSent(AutomationMail::class, 1);
    }

    public function test_sent_reminder_contains_public_url_and_attaches_current_pdf_without_regeneration(): void
    {
        Mail::fake();
        [$invoice, $service] = $this->environment('send', secondDay: null);
        $link = InvoicePublicLink::query()->active()->where('invoice_id', $invoice->id)->sole();
        $url = app(InvoicePublicLinkService::class)->url($link);
        $this->assertNotNull($url);

        $reminder = $service->prepare($invoice, 1, CarbonImmutable::parse('2026-08-17'), true);
        $document = InvoiceDocument::query()->where('invoice_id', $invoice->id)->sole();

        $this->assertSame('sent', $reminder->status);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertStringEndsWith('.pdf', $document->original_filename);
        Storage::disk(InvoicePdfGenerator::DISK)->assertExists($document->storage_path);
        $this->assertStringContainsString($url, $reminder->body_text);
        $this->assertStringContainsString('100 Kč', $reminder->body_text);
        Mail::assertSent(AutomationMail::class, function (AutomationMail $mail) use ($document, $url): bool {
            $attachment = Attachment::fromStorageDisk($document->storage_disk, $document->storage_path)
                ->as($document->original_filename)
                ->withMime('application/pdf');

            return $mail->hasAttachment($attachment)
                && $mail->webInvoiceUrl === $url
                && str_contains($mail->bodyText, $url)
                && str_contains($mail->render(), 'href="'.$url.'"');
        });

        $this->assertSame(1, InvoicePublicLink::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, InvoiceDocument::query()->where('invoice_id', $invoice->id)->count());
        $service->send($reminder->refresh(), InvoiceReminderOrigin::Manual);
        $this->assertSame(1, InvoiceDocument::query()->where('invoice_id', $invoice->id)->count());
        Mail::assertSent(AutomationMail::class, 1);
    }

    public function test_smtp_failure_preserves_assets_and_retry_uses_same_reminder_link_and_pdf(): void
    {
        [$invoice, $service] = $this->environment('send', secondDay: null);
        $reminder = $service->prepare($invoice, 1, CarbonImmutable::parse('2026-08-17'), false);

        $deliveryAttempt = 0;
        Mail::shouldReceive('to')->twice()->with($reminder->recipient_email)->andReturnSelf();
        Mail::shouldReceive('send')->twice()->andReturnUsing(function () use (&$deliveryAttempt): void {
            $deliveryAttempt++;
            if ($deliveryAttempt === 1) {
                throw new \RuntimeException('SMTP unavailable');
            }
        });

        try {
            $service->send($reminder, InvoiceReminderOrigin::Manual);
            $this->fail('SMTP failure must be propagated after the reminder is marked as failed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SMTP unavailable', $exception->getMessage());
        }

        $failed = $reminder->refresh();
        $link = InvoicePublicLink::query()->active()->where('invoice_id', $invoice->id)->sole();
        $document = InvoiceDocument::query()->where('invoice_id', $invoice->id)->sole();
        $url = app(InvoicePublicLinkService::class)->url($link);
        $this->assertSame('failed', $failed->status);
        $this->assertSame(1, $failed->send_attempts);
        $this->assertNotNull($url);
        $this->assertStringContainsString($url, $failed->body_text);
        Storage::disk(InvoicePdfGenerator::DISK)->assertExists($document->storage_path);

        $sent = $service->send($failed, InvoiceReminderOrigin::Manual);

        $this->assertSame('sent', $sent->status);
        $this->assertSame(2, $sent->send_attempts);
        $this->assertSame($link->uuid, InvoicePublicLink::query()->active()->where('invoice_id', $invoice->id)->sole()->uuid);
        $this->assertSame($document->uuid, InvoiceDocument::query()->where('invoice_id', $invoice->id)->sole()->uuid);
        $this->assertSame(2, $deliveryAttempt);
    }

    public function test_prepared_reminder_in_prepare_mode_is_not_sent(): void
    {
        Mail::fake();
        [$invoice, $service] = $this->environment('prepare', secondDay: null);
        $reminder = $service->prepare($invoice, 1, CarbonImmutable::parse('2026-08-17'), false);

        $this->assertSame(0, $service->runDue(CarbonImmutable::parse('2026-08-24'))['processed']);
        $this->assertSame('prepared', $reminder->refresh()->status);
        $this->assertSame(0, $reminder->send_attempts);
        $this->assertSame(0, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.reminder_sent')->count());
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
        [$invoice, $service, $admin, $business] = $this->environment('send', secondDay: null);
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
        $audit = DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.reminder_sent')->sole();
        $values = json_decode($audit->new_values, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($invoice->uuid, $audit->auditable_uuid);
        $this->assertSame($reminder->uuid, $values['reminder_uuid']);
        $this->assertSame(1, $values['level']);
        $this->assertSame('manual', $values['origin']);
        $this->assertSame($admin->name, $audit->actor_name);

        $this->withSession($this->deliveryBusinessSession($business))
            ->post(route('invoices.reminders.send', $invoice->uuid), ['level' => 1])
            ->assertRedirect(route('invoices.show', $invoice->uuid));
        $this->assertSame(1, $reminder->refresh()->send_attempts);
        Mail::assertSent(AutomationMail::class, 1);
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.reminder_sent')->count());
    }

    public function test_reminder_pages_distinguish_states_and_invoice_detail_shows_history(): void
    {
        Mail::fake();
        [$invoice, $service, , $business] = $this->environment('prepare', secondDay: null);
        $reminder = $service->prepare($invoice, 1, CarbonImmutable::parse('2026-08-17'), false);

        $this->withSession($this->deliveryBusinessSession($business))
            ->get(route('invoices.reminders.form', $invoice->uuid))
            ->assertOk()
            ->assertSee('Tato upomínka je připravena k odeslání.');

        $reminder->forceFill(['status' => 'failed'])->save();
        $this->withSession($this->deliveryBusinessSession($business))
            ->get(route('invoices.reminders.form', $invoice->uuid))
            ->assertOk()
            ->assertSee('Předchozí pokus selhal.');

        $service->send($reminder->refresh(), InvoiceReminderOrigin::Manual);

        $this->withSession($this->deliveryBusinessSession($business))
            ->get(route('invoices.reminders.form', $invoice->uuid))
            ->assertOk()
            ->assertSee('Tato upomínka již byla odeslána');
        $this->withSession($this->deliveryBusinessSession($business))
            ->get(route('invoices.show', $invoice->uuid))
            ->assertOk()
            ->assertSee('Historie upomínek')
            ->assertSee('1. upomínka')
            ->assertSee('Odeslaná')
            ->assertSee('Odeslána 1. upomínka');
    }

    public function test_successful_manual_send_is_visible_in_invoice_http_audit_history(): void
    {
        Mail::fake();
        [$invoice, $service, $admin, $business] = $this->environment('prepare', secondDay: null);
        $service->prepare($invoice, 1, CarbonImmutable::parse('2026-08-17'), false);

        $this->withSession($this->deliveryBusinessSession($business))
            ->post(route('invoices.reminders.send', $invoice->uuid), ['level' => 1])
            ->assertRedirect(route('invoices.show', $invoice->uuid));

        Mail::assertSent(AutomationMail::class, 1);
        $this->withSession($this->deliveryBusinessSession($business))
            ->get(route('invoices.show', $invoice->uuid))
            ->assertOk()
            ->assertSee('Odeslána 1. upomínka')
            ->assertSee($admin->name);
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
