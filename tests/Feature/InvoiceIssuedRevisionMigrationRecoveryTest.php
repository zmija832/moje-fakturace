<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business\Invoice;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceIssuedRevisionMigrationRecoveryTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;
    use InteractsWithBusinessDatabases;

    private const TARGET_MIGRATION = '2026_08_16_000000_enable_issued_invoice_revision_workflow';

    protected bool $businessDatabaseTransactions = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetBusinessTestMigrations();
    }

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_clean_legacy_business_schema_backfills_documents_and_restores_immutable_guard(): void
    {
        $connection = BusinessConnection::Business2;
        $this->migrateLegacySchema($connection);
        [$invoice, $documentId] = $this->createLegacyDocument($connection);
        $this->assertFalse(Schema::connection($connection->connectionName())->hasColumn('invoice_documents', 'invoice_revision_id'));

        $this->assertSame(0, $this->runTargetMigration($connection));

        $this->assertCompletedMigration($connection, $invoice, $documentId);
    }

    public function test_partial_production_state_is_resumed_and_business_command_finishes_both_tenants(): void
    {
        $connection = BusinessConnection::Business1;
        $this->migrateLegacySchema($connection);
        [$invoice, $documentId] = $this->createLegacyDocument($connection);
        Schema::connection($connection->connectionName())->table('invoice_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_revision_id')->nullable()->after('invoice_id');
        });

        $this->assertNull(DB::connection($connection->connectionName())->table('invoice_documents')->where('id', $documentId)->value('invoice_revision_id'));
        $this->assertFalse(DB::connection($connection->connectionName())->table('migrations')->where('migration', self::TARGET_MIGRATION)->exists());
        $this->assertStringContainsString(
            'Invoice document is immutable',
            (string) $this->trigger($connection, 'invoice_documents_immutable_update')->ACTION_STATEMENT,
        );

        $this->assertSame(0, $this->runTargetMigration($connection));
        $this->assertCompletedMigration($connection, $invoice, $documentId);

        $this->assertSame(0, Artisan::call('app:migrate-businesses'));
        foreach (BusinessConnection::cases() as $businessConnection) {
            $name = $businessConnection->connectionName();
            $this->assertTrue(Schema::connection($name)->hasColumn('invoice_documents', 'invoice_revision_id'));
            $this->assertTrue(Schema::connection($name)->hasTable('invoice_issued_revision_operations'));
            $this->assertTrue(Schema::connection($name)->hasTable('invoice_email_settings'));
            $this->assertSame(1, DB::connection($name)->table('migrations')->where('migration', self::TARGET_MIGRATION)->count());
        }
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_failed_validation_restores_strict_document_guard_and_clears_session_token(): void
    {
        $connection = BusinessConnection::Business1;
        $this->migrateLegacySchema($connection);
        [$invoice] = $this->createLegacyDocument($connection);
        Schema::connection($connection->connectionName())->table('invoice_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_revision_id')->nullable()->after('invoice_id');
        });
        $badDocumentId = $this->insertDocument($connection, $invoice, ((int) $invoice->issued_revision_id) + 999999);

        try {
            $this->runTargetMigration($connection);
            $this->fail('Migrace nesmí přijmout dokument navázaný na jinou revizi.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nelze bezpečně navázat', $exception->getMessage());
        }
        $this->assertFalse(DB::connection($connection->connectionName())->table('migrations')->where('migration', self::TARGET_MIGRATION)->exists());
        $this->assertStringNotContainsString(
            'invoice_document_revision_backfill_token',
            (string) $this->trigger($connection, 'invoice_documents_immutable_update')->ACTION_STATEMENT,
        );
        $this->assertNull(DB::connection($connection->connectionName())->selectOne('SELECT @invoice_document_revision_backfill_token AS token')->token);
        $this->assertImmutableUpdateRejected($connection, $badDocumentId);
    }

    /** @return array{Invoice,int} */
    private function createLegacyDocument(BusinessConnection $connection): array
    {
        [, $business] = $this->deliveryMembership(connection: $connection);
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $documentId = $this->insertDocument($connection, $invoice);
        app(ActiveBusinessContext::class)->clear();

        return [$invoice, $documentId];
    }

    private function insertDocument(BusinessConnection $connection, Invoice $invoice, ?int $revisionId = null): int
    {
        $values = [
            'uuid' => (string) Str::uuid(),
            'invoice_id' => $invoice->id,
            'document_type' => 'invoice_pdf',
            'storage_disk' => 'invoice_documents',
            'storage_path' => 'migration/'.Str::uuid().'.pdf',
            'original_filename' => 'legacy.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => str_repeat('a', 64),
            'template_version' => 'invoice-v1',
            'generated_at' => now(),
            'generation_correlation_uuid' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($revisionId !== null) {
            $values['invoice_revision_id'] = $revisionId;
        }

        return (int) DB::connection($connection->connectionName())->table('invoice_documents')->insertGetId($values);
    }

    private function assertCompletedMigration(BusinessConnection $connection, Invoice $invoice, int $documentId): void
    {
        $name = $connection->connectionName();
        $this->assertSame((int) $invoice->issued_revision_id, (int) DB::connection($name)
            ->table('invoice_documents')->where('id', $documentId)->value('invoice_revision_id'));
        $column = DB::connection($name)->selectOne("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_documents' AND COLUMN_NAME = 'invoice_revision_id'");
        $this->assertSame('NO', $column->IS_NULLABLE);
        $this->assertSame(1, (int) DB::connection($name)->selectOne("SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_documents' AND CONSTRAINT_NAME = 'invoice_documents_revision_invoice_foreign' AND CONSTRAINT_TYPE = 'FOREIGN KEY'")->aggregate);
        $this->assertGreaterThan(0, (int) DB::connection($name)->selectOne("SELECT COUNT(*) AS aggregate FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_documents' AND INDEX_NAME = 'invoice_documents_revision_latest_index'")->aggregate);
        $this->assertTrue(Schema::connection($name)->hasTable('invoice_issued_revision_operations'));
        $this->assertSame(1, DB::connection($name)->table('migrations')->where('migration', self::TARGET_MIGRATION)->count());
        $this->assertStringNotContainsString('invoice_document_revision_backfill_token', (string) $this->trigger($connection, 'invoice_documents_immutable_update')->ACTION_STATEMENT);
        $this->assertNull(DB::connection($name)->selectOne('SELECT @invoice_document_revision_backfill_token AS token')->token);
        $this->assertImmutableUpdateRejected($connection, $documentId);
    }

    private function assertImmutableUpdateRejected(BusinessConnection $connection, int $documentId): void
    {
        try {
            DB::connection($connection->connectionName())->table('invoice_documents')->where('id', $documentId)
                ->update(['original_filename' => 'tampered.pdf']);
            $this->fail('Runtime UPDATE immutable PDF dokumentu nesmí projít.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Invoice document is immutable', $exception->getMessage());
        }
        $this->assertSame('legacy.pdf', DB::connection($connection->connectionName())->table('invoice_documents')->where('id', $documentId)->value('original_filename'));
    }

    private function migrateLegacySchema(BusinessConnection $connection): void
    {
        $paths = collect(glob(database_path('migrations/business/*.php')))
            ->filter(fn (string $path): bool => basename($path, '.php') < self::TARGET_MIGRATION)
            ->values()->all();
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => $connection->connectionName(),
            '--path' => $paths,
            '--realpath' => true,
            '--force' => true,
        ]));
    }

    private function runTargetMigration(BusinessConnection $connection): int
    {
        return Artisan::call('migrate', [
            '--database' => $connection->connectionName(),
            '--path' => [database_path('migrations/business/'.self::TARGET_MIGRATION.'.php')],
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    private function trigger(BusinessConnection $connection, string $trigger): object
    {
        return DB::connection($connection->connectionName())->selectOne(
            'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );
    }
}
