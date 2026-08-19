<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business\RecurringInvoiceRun;
use App\Models\Business\RecurringInvoiceTemplate;
use App\Services\Business\InvoicePaidNotificationService;
use App\Services\Business\InvoiceReminderService;
use App\Services\Business\RecurringInvoiceRunner;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\TestCase;

class InvoiceAutomationCommandDiagnosticsTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_work_item_exception_is_reported_and_runner_continues(): void
    {
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [, $client, $account] = $this->createIssuedInvoice(false);
        $this->template($client->uuid, $account->uuid, 'První', 1);
        $this->template($client->uuid, $account->uuid, 'Druhý', 2);
        $exception = new RuntimeException('diagnostic-test');
        $calls = 0;
        $runner = Mockery::mock(RecurringInvoiceRunner::class)->makePartial();
        $runner->shouldReceive('run')->twice()->andReturnUsing(function () use (&$calls, $exception): RecurringInvoiceRun {
            if (++$calls === 1) {
                throw $exception;
            }

            return new RecurringInvoiceRun;
        });
        Exceptions::fake();

        $result = $runner->runDue(now()->toImmutable(), 10);

        $this->assertSame(['processed' => 1, 'failed' => 1], $result);
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
    }

    public function test_first_tenant_failure_is_reported_and_does_not_block_second_tenant(): void
    {
        [, $first] = $this->deliveryMembership();
        [, $second] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        $exception = new RuntimeException('tenant-one-failure');
        $calls = 0;
        $recurring = Mockery::mock(RecurringInvoiceRunner::class);
        $recurring->shouldReceive('runDue')->twice()->andReturnUsing(function () use (&$calls, $exception): array {
            if (++$calls === 1) {
                throw $exception;
            }

            return ['processed' => 0, 'failed' => 0];
        });
        $this->bindAutomationServices($recurring);
        Exceptions::fake();

        $this->artisan('app:run-invoice-automation')
            ->expectsOutputToContain($first->display_name)
            ->expectsOutputToContain($second->display_name)
            ->assertFailed();

        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
        $this->assertNull(app(ActiveBusinessContext::class)->business());
    }

    public function test_explicit_unknown_or_inactive_business_fails_safely(): void
    {
        $this->artisan('app:run-invoice-automation', ['--business' => (string) Str::uuid()])
            ->expectsOutputToContain('nebyl nalezen')
            ->assertFailed();
    }

    public function test_explicit_business_processes_only_selected_tenant(): void
    {
        [, $first] = $this->deliveryMembership();
        [, $second] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        $recurring = Mockery::mock(RecurringInvoiceRunner::class);
        $recurring->shouldReceive('runDue')->once()->andReturnUsing(function (): array {
            $this->assertSame('business_2', app(ActiveBusinessContext::class)->connectionName());

            return ['processed' => 0, 'failed' => 0];
        });
        $this->bindAutomationServices($recurring);

        $this->artisan('app:run-invoice-automation', ['--business' => $second->uuid])
            ->doesntExpectOutputToContain($first->display_name)
            ->expectsOutputToContain($second->display_name)
            ->assertSuccessful();
    }

    public function test_no_pending_work_succeeds_and_failed_result_fails(): void
    {
        [, $business] = $this->deliveryMembership();
        $this->bindAutomationServices($this->recurringResult(['processed' => 0, 'failed' => 0]));
        $this->artisan('app:run-invoice-automation', ['--business' => $business->uuid])->assertSuccessful();

        $this->bindAutomationServices($this->recurringResult(['processed' => 1, 'failed' => 1]));
        $this->artisan('app:run-invoice-automation', ['--business' => $business->uuid])->assertFailed();
    }

    private function bindAutomationServices(object $recurring): void
    {
        app()->instance(RecurringInvoiceRunner::class, $recurring);
        $reminders = Mockery::mock(InvoiceReminderService::class);
        $reminders->shouldReceive('runDue')->once()->andReturn(['processed' => 0, 'failed' => 0]);
        app()->instance(InvoiceReminderService::class, $reminders);
        $paid = Mockery::mock(InvoicePaidNotificationService::class);
        $paid->shouldReceive('retryDue')->once()->andReturn(['processed' => 0, 'failed' => 0]);
        app()->instance(InvoicePaidNotificationService::class, $paid);
    }

    private function recurringResult(array $result): object
    {
        $runner = Mockery::mock(RecurringInvoiceRunner::class);
        $runner->shouldReceive('runDue')->once()->andReturn($result);

        return $runner;
    }

    private function template(string $clientUuid, string $accountUuid, string $name, int $sort): RecurringInvoiceTemplate
    {
        $template = new RecurringInvoiceTemplate;
        $template->forceFill([
            'name' => $name,
            'client_uuid' => $clientUuid,
            'bank_account_uuid' => $accountUuid,
            'currency' => 'CZK',
            'payment_method' => 'bank_transfer',
            'due_days' => 14,
            'interval_months' => 1,
            'anchor_day' => 19,
            'next_run_on' => now()->toDateString(),
            'mode' => 'draft',
            'auto_send' => false,
            'is_active' => true,
            'invoice_discount_type' => 'none',
            'version' => $sort,
        ]);
        $template->save();

        return $template;
    }
}
