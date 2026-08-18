<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Services\Business\InvoiceDuplicator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBusinessProcessEnvironment;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceDeletionConcurrencyTest extends TestCase
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

    public function test_deleting_last_invoice_and_issuing_next_one_never_duplicate_number(): void
    {
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $draft = app(InvoiceDuplicator::class)->duplicate($invoice);
        $barrier = storage_path('framework/testing/invoice-delete-'.Str::uuid());
        $environment = $this->businessChildProcessEnvironment();
        $processes = [
            new Process([
                PHP_BINARY, base_path('tests/Support/delete-invoice.php'),
                BusinessConnection::Business1->connectionName(), $business->uuid, $invoice->uuid, $barrier,
            ], base_path(), $environment),
            new Process([
                PHP_BINARY, base_path('tests/Support/issue-invoice.php'),
                BusinessConnection::Business1->connectionName(), $draft->uuid, (string) $draft->version,
                (string) Str::uuid(), $barrier,
            ], base_path(), $environment),
        ];

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

        $this->assertFalse(DB::connection('business_1')->table('invoices')->where('id', $invoice->id)->exists());
        $issued = DB::connection('business_1')->table('invoices')->where('id', $draft->id)->sole();
        $this->assertSame('issued', $issued->status);
        $allocations = DB::connection('business_1')->table('document_number_allocations')->get();
        $this->assertCount(1, $allocations);
        $this->assertSame(1, $allocations->pluck('formatted_number')->unique()->count());
        $this->assertSame((int) $allocations->first()->sequence_number + 1, (int) DB::connection('business_1')
            ->table('document_sequences')->where('id', $issued->document_sequence_id)->value('next_number'));
    }
}
