<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business\InvoicePayment;
use App\Services\Business\InvoicePaymentReader;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBusinessProcessEnvironment;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoicePaymentConcurrencyTest extends TestCase
{
    use BuildsBusinessProcessEnvironment;
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

    public function test_two_concurrent_payments_are_both_recorded_without_lost_update(): void
    {
        [$invoice, $processes, $barrier] = $this->processes(['35.0001', '64.9999'], [(string) Str::uuid(), (string) Str::uuid()]);

        $this->runProcesses($processes, $barrier);

        $this->assertSame(2, InvoicePayment::query()->count());
        $summary = app(InvoicePaymentReader::class)->summary($invoice->fresh());
        $this->assertSame('100.0000', $summary->paidTotal);
        $this->assertSame('0.0000', $summary->remainingTotal);
        $this->assertSame('paid', $summary->status->value);
    }

    public function test_same_concurrent_correlation_creates_exactly_one_ledger_entry(): void
    {
        $correlation = (string) Str::uuid();
        [$invoice, $processes, $barrier] = $this->processes(['15.0000', '15.0000'], [$correlation, $correlation]);

        $this->runProcesses($processes, $barrier);

        $payment = InvoicePayment::query()->sole();
        $this->assertSame($correlation, $payment->correlation_uuid);
        $this->assertSame('15.0000', app(InvoicePaymentReader::class)->summary($invoice->fresh())->paidTotal);
    }

    /** @param list<string> $amounts @param list<string> $correlations @return array{mixed, list<Process>, string} */
    private function processes(array $amounts, array $correlations): array
    {
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $barrier = storage_path('framework/testing/invoice-payment-'.Str::uuid());
        $environment = $this->businessChildProcessEnvironment();
        $processes = [];
        foreach ($amounts as $index => $amount) {
            $processes[] = new Process([
                PHP_BINARY, base_path('tests/Support/record-invoice-payment.php'),
                BusinessConnection::Business1->connectionName(), $business->uuid, $invoice->uuid,
                $correlations[$index], $amount, $barrier,
            ], base_path(), $environment);
        }

        return [$invoice, $processes, $barrier];
    }

    /** @param list<Process> $processes */
    private function runProcesses(array $processes, string $barrier): void
    {
        try {
            foreach ($processes as $process) {
                $process->setTimeout(45);
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
            foreach ($processes as $process) {
                $this->assertFalse($process->isRunning());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }
    }
}
