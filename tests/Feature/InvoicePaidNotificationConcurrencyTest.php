<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Events\InvoicePaymentChanged;
use App\Models\Business\InvoicePaidNotification;
use App\Services\Business\InvoiceAutomationSettingsService;
use App\Services\Business\InvoicePaymentService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBusinessProcessEnvironment;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoicePaidNotificationConcurrencyTest extends TestCase
{
    use BuildsBusinessProcessEnvironment, CreatesInvoiceDeliveryFixtures, InteractsWithBusinessDatabases;

    protected bool $businessDatabaseTransactions = false;

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

    public function test_two_paid_handlers_create_one_event_and_one_delivery_claim(): void
    {
        [$user, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        $this->actingAs($user);
        [$invoice] = $this->createIssuedInvoice();
        $settings = app(InvoiceAutomationSettingsService::class);
        $settings->save([
            ...$settings->defaults(),
            'notify_admin_when_paid' => true,
            'notify_customer_when_paid' => true,
        ]);
        Event::fake([InvoicePaymentChanged::class]);
        $payment = app(InvoicePaymentService::class)->record($invoice->uuid, (string) Str::uuid(), [
            'amount' => '100',
            'currency' => 'CZK',
            'paid_on' => '2026-08-19',
            'payment_method' => 'bank_transfer',
        ]);
        $payload = base64_encode(json_encode([
            'invoiceUuid' => $invoice->uuid,
            'documentNumber' => (string) $invoice->document_number,
            'paymentUuid' => $payment->uuid,
            'paymentType' => 'payment',
            'amount' => '100.0000',
            'currency' => 'CZK',
            'paidOn' => '2026-08-19',
            'statusBefore' => 'unpaid',
            'statusAfter' => 'paid',
            'paidTotal' => '100.0000',
            'remainingTotal' => '0.0000',
            'notificationIntents' => ['client.invoice.payment_confirmation'],
        ], JSON_THROW_ON_ERROR));
        $barrier = storage_path('framework/testing/paid-notification-'.Str::uuid());
        $environment = $this->businessChildProcessEnvironment();
        $processes = [];
        foreach ([1, 2] as $_) {
            $processes[] = new Process($this->businessPhpCommand(
                base_path('tests/Support/handle-paid-notification.php'),
                ['business_1', $business->uuid, $payload, $barrier],
            ), base_path(), $environment);
        }

        try {
            foreach ($processes as $process) {
                $process->setTimeout(90);
                $process->start();
            }
            file_put_contents($barrier, 'start');
            foreach ($processes as $process) {
                $process->wait();
            }
            $this->assertSame(
                [0, 0],
                array_map(fn (Process $process): int => $process->getExitCode() ?? -1, $processes),
                implode("\n", array_map(fn (Process $process): string => $process->getErrorOutput(), $processes)),
            );
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
                $this->assertFalse($process->isRunning());
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }

        $this->assertSame(1, InvoicePaidNotification::query()->where('recipient_type', 'admin')->count());
        $customer = InvoicePaidNotification::query()->where('recipient_type', 'customer')->sole();
        $this->assertSame('sent', $customer->status);
        $this->assertSame(1, $customer->send_attempts);
        $this->assertNull($customer->claim_token);
        $this->assertNull($customer->claimed_at);
    }
}
