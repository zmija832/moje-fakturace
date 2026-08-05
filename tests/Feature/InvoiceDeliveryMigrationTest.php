<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business\InvoiceDocument;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceDeliveryMigrationTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;
    use InteractsWithBusinessDatabases;

    protected bool $businessDatabaseTransactions = false;

    public function test_document_and_delivery_schema_exists_only_in_identical_business_databases(): void
    {
        $this->refreshBusinessTestDatabases();
        $expected = ['invoice_documents', 'invoice_email_deliveries'];
        foreach (BusinessConnection::cases() as $connection) {
            foreach ($expected as $table) {
                $this->assertTrue(Schema::connection($connection->connectionName())->hasTable($table));
                $this->assertFalse(Schema::connection($connection->connectionName())->hasColumn($table, 'business_id'));
            }
        }
        foreach ($expected as $table) {
            $this->assertFalse(Schema::connection('central')->hasTable($table));
        }
        $columnsOne = DB::connection('business_1')->select("SELECT table_name,column_name,column_type,is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('invoice_documents','invoice_email_deliveries') ORDER BY table_name,ordinal_position");
        $columnsTwo = DB::connection('business_2')->select("SELECT table_name,column_name,column_type,is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('invoice_documents','invoice_email_deliveries') ORDER BY table_name,ordinal_position");
        $this->assertEquals($columnsOne, $columnsTwo);

        $foreignKeys = DB::connection('business_1')->select("SELECT referenced_table_schema,referenced_table_name FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name IN ('invoice_documents','invoice_email_deliveries') AND referenced_table_name IS NOT NULL");
        $this->assertNotEmpty($foreignKeys);
        foreach ($foreignKeys as $foreignKey) {
            $this->assertSame(config('database.connections.business_1.database'), $foreignKey->referenced_table_schema);
            $this->assertContains($foreignKey->referenced_table_name, ['invoices', 'invoice_documents']);
        }
        foreach (BusinessConnection::cases() as $connection) {
            $connectionName = $connection->connectionName();
            $uniqueIndexes = DB::connection($connectionName)->select("SELECT table_name,index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND non_unique = 0 AND ((table_name = 'invoice_documents' AND column_name = 'generation_correlation_uuid') OR (table_name = 'invoice_email_deliveries' AND column_name = 'send_correlation_uuid'))");
            $this->assertCount(2, $uniqueIndexes);
            $triggers = DB::connection($connectionName)->select("SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND event_object_table IN ('invoice_documents','invoice_email_deliveries')");
            $this->assertCount(5, $triggers);
        }
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_down_refuses_to_discard_existing_document_history(): void
    {
        $this->refreshBusinessTestDatabases();
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $correlation = (string) Str::uuid();
        $document = new InvoiceDocument;
        $document->forceFill([
            'invoice_id' => $invoice->id,
            'document_type' => 'invoice_pdf',
            'storage_disk' => 'invoice_documents',
            'storage_path' => 'test/immutable.pdf',
            'original_filename' => 'faktura-test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => str_repeat('a', 64),
            'template_version' => 'invoice-v1',
            'generated_at' => now(),
            'generation_correlation_uuid' => $correlation,
        ])->save();
        try {
            $duplicate = new InvoiceDocument;
            $duplicate->forceFill([
                'invoice_id' => $invoice->id,
                'document_type' => 'invoice_pdf',
                'storage_disk' => 'invoice_documents',
                'storage_path' => 'test/duplicate.pdf',
                'original_filename' => 'faktura-duplicate.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 10,
                'sha256' => str_repeat('b', 64),
                'template_version' => 'invoice-v1',
                'generated_at' => now(),
                'generation_correlation_uuid' => $correlation,
            ])->save();
            $this->fail('Unikátní generation correlation UUID nesmí vytvořit druhý dokument.');
        } catch (QueryException) {
            $this->assertSame(1, InvoiceDocument::query()->count());
        }

        try {
            $deliveryBatch = (int) DB::connection(BusinessConnection::Business1->connectionName())
                ->table('migrations')
                ->where('migration', '2026_08_03_000000_add_invoice_documents_and_deliveries')
                ->value('batch');
            $this->assertGreaterThan(0, $deliveryBatch);

            $exitCode = Artisan::call('migrate:rollback', [
                '--database' => BusinessConnection::Business1->connectionName(),
                '--path' => [database_path('migrations/business/2026_08_03_000000_add_invoice_documents_and_deliveries.php')],
                '--realpath' => true,
                '--batch' => $deliveryBatch,
                '--force' => true,
            ]);
            $this->assertNotSame(0, $exitCode);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('historie PDF nebo odeslání', $exception->getMessage());
        }

        $this->assertTrue(Schema::connection('business_1')->hasTable('invoice_documents'));
        $this->assertSame(1, InvoiceDocument::query()->count());
        $this->assertSame('central', DB::getDefaultConnection());
        app(ActiveBusinessContext::class)->clear();
    }

    public function test_database_rejects_document_for_draft_invoice(): void
    {
        $this->refreshBusinessTestDatabases();
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);

        try {
            $document = new InvoiceDocument;
            $document->forceFill([
                'invoice_id' => $draft->id,
                'document_type' => 'invoice_pdf',
                'storage_disk' => 'invoice_documents',
                'storage_path' => 'test/draft.pdf',
                'original_filename' => 'faktura-draft.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 10,
                'sha256' => str_repeat('c', 64),
                'template_version' => 'invoice-v1',
                'generated_at' => now(),
                'generation_correlation_uuid' => (string) Str::uuid(),
            ])->save();
            $this->fail('Databáze nesmí přijmout dokument draftu.');
        } catch (QueryException) {
            $this->assertSame(0, InvoiceDocument::query()->count());
        } finally {
            app(ActiveBusinessContext::class)->clear();
        }
    }
}
