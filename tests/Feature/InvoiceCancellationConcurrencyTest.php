<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBusinessProcessEnvironment;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceCancellationConcurrencyTest extends TestCase
{
    use BuildsBusinessProcessEnvironment;
    use CreatesInvoiceDeliveryFixtures;
    use InteractsWithBusinessDatabases;

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

    public function test_same_concurrent_cancellation_is_applied_and_audited_exactly_once(): void
    {
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $correlation = (string) Str::uuid();
        $barrier = storage_path('framework/testing/invoice-cancel-'.Str::uuid());
        $environment = $this->businessChildProcessEnvironment();
        $processes = array_map(fn (): Process => new Process([
            PHP_BINARY, base_path('tests/Support/cancel-invoice.php'),
            BusinessConnection::Business1->connectionName(), $business->uuid, $invoice->uuid,
            $correlation, (string) $invoice->version, $barrier,
        ], base_path(), $environment), [1, 2]);

        try {
            foreach ($processes as $process) {
                $process->setTimeout(60);
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

        $this->assertSame('cancelled', DB::connection('business_1')->table('invoices')->where('id', $invoice->id)->value('status'));
        $this->assertSame($correlation, DB::connection('business_1')->table('invoices')->where('id', $invoice->id)->value('cancellation_correlation_uuid'));
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.cancelled')->count());
    }
}
