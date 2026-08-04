<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business\InvoiceDocument;
use App\Models\Business\InvoiceEmailDelivery;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBusinessProcessEnvironment;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceDeliveryConcurrencyTest extends TestCase
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

    public function test_two_processes_with_same_correlation_create_one_pdf_and_remove_temporary_files(): void
    {
        if (! class_exists(Dompdf::class) || ! class_exists(Writer::class)) {
            $this->markTestSkipped('PDF/QR knihovny nejsou nainstalované ve vendor.');
        }

        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $this->actingAs($admin);

        $storageRoot = storage_path('framework/testing/invoice-documents-'.Str::uuid());
        config()->set('filesystems.disks.invoice_documents.root', $storageRoot);
        Storage::forgetDisk('invoice_documents');
        $barrier = storage_path('framework/testing/invoice-pdf-'.Str::uuid());
        $correlation = (string) Str::uuid();
        $environment = array_merge($this->businessChildProcessEnvironment(), [
            'INVOICE_DOCUMENTS_ROOT' => $storageRoot,
        ]);
        $processes = [];

        for ($index = 0; $index < 2; $index++) {
            $processes[] = new Process([
                PHP_BINARY,
                base_path('tests/Support/generate-invoice-pdf.php'),
                BusinessConnection::Business1->connectionName(),
                $business->uuid,
                $invoice->uuid,
                $correlation,
                $barrier,
            ], base_path(), $environment);
        }

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
            $document = InvoiceDocument::query()->sole();
            $this->assertSame(1, InvoiceDocument::query()->count());
            $this->assertSame($correlation, $document->generation_correlation_uuid);
            $this->assertTrue(Storage::disk('invoice_documents')->exists($document->storage_path));
            $this->assertSame([], Storage::disk('invoice_documents')->files('tmp'));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
            File::deleteDirectory($storageRoot);
        }
    }

    public function test_two_processes_with_same_send_correlation_create_one_delivery(): void
    {
        if (! class_exists(Dompdf::class) || ! class_exists(Writer::class)) {
            $this->markTestSkipped('PDF/QR knihovny nejsou nainstalované ve vendor.');
        }

        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $this->actingAs($admin);

        $storageRoot = storage_path('framework/testing/invoice-mail-documents-'.Str::uuid());
        config()->set('filesystems.disks.invoice_documents.root', $storageRoot);
        Storage::forgetDisk('invoice_documents');
        $barrier = storage_path('framework/testing/invoice-mail-'.Str::uuid());
        $correlation = (string) Str::uuid();
        $environment = array_merge($this->businessChildProcessEnvironment(), [
            'INVOICE_DOCUMENTS_ROOT' => $storageRoot,
            'MAIL_MAILER' => 'array',
        ]);
        $processes = [];

        for ($index = 0; $index < 2; $index++) {
            $processes[] = new Process([
                PHP_BINARY,
                base_path('tests/Support/send-invoice-email.php'),
                BusinessConnection::Business1->connectionName(),
                $business->uuid,
                $invoice->uuid,
                $correlation,
                $barrier,
            ], base_path(), $environment);
        }

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
            $delivery = InvoiceEmailDelivery::query()->sole();
            $this->assertSame('sent', $delivery->status->value);
            $this->assertSame($correlation, $delivery->send_correlation_uuid);
            $this->assertSame(1, InvoiceDocument::query()->count());
            $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.email_send_requested')->count());
            $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.email_sent')->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
            File::deleteDirectory($storageRoot);
        }
    }
}
