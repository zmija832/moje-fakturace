<?php

use App\Enums\BusinessConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DOCUMENT_REVISION_FOREIGN = 'invoice_documents_revision_invoice_foreign';

    private const DOCUMENT_REVISION_INDEX = 'invoice_documents_revision_latest_index';

    public function up(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();

        $this->ensureDocumentRevisionColumn($connection);
        $this->backfillDocumentRevisions($connection);
        $this->ensureDocumentRevisionConstraints($connection);
        $this->ensureIssuedRevisionOperationsTable($connection);
        $this->replaceIssuedGuards($connection);
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        if (Schema::connection($connection)->hasTable('invoice_issued_revision_operations')
            && DB::connection($connection)->table('invoice_issued_revision_operations')->exists()) {
            throw new RuntimeException('Workflow admin úprav vystavených faktur nelze vrátit, pokud existují jeho operace.');
        }

        $this->createLegacyIssuedGuards($connection);
        Schema::connection($connection)->dropIfExists('invoice_issued_revision_operations');

        if (! Schema::connection($connection)->hasTable('invoice_documents')
            || ! Schema::connection($connection)->hasColumn('invoice_documents', 'invoice_revision_id')) {
            return;
        }
        if ($this->foreignKeyExists($connection, 'invoice_documents', self::DOCUMENT_REVISION_FOREIGN)) {
            Schema::connection($connection)->table('invoice_documents', function (Blueprint $table): void {
                $table->dropForeign(self::DOCUMENT_REVISION_FOREIGN);
            });
        }
        if ($this->indexExists($connection, 'invoice_documents', self::DOCUMENT_REVISION_INDEX)) {
            Schema::connection($connection)->table('invoice_documents', function (Blueprint $table): void {
                $table->dropIndex(self::DOCUMENT_REVISION_INDEX);
            });
        }
        Schema::connection($connection)->table('invoice_documents', function (Blueprint $table): void {
            $table->dropColumn('invoice_revision_id');
        });
    }

    private function ensureDocumentRevisionColumn(string $connection): void
    {
        if (Schema::connection($connection)->hasColumn('invoice_documents', 'invoice_revision_id')) {
            return;
        }

        Schema::connection($connection)->table('invoice_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_revision_id')->nullable()->after('invoice_id');
        });
    }

    private function backfillDocumentRevisions(string $connection): void
    {
        if (DB::connection($connection)->table('invoice_documents')->whereNull('invoice_revision_id')->doesntExist()) {
            $this->assertDocumentRevisionLinks($connection);

            return;
        }

        $token = bin2hex(random_bytes(32));
        $this->createDocumentBackfillGuard($connection, $token);

        try {
            DB::connection($connection)->statement('SET @invoice_document_revision_backfill_token = ?', [$token]);
            DB::connection($connection)->statement(<<<'SQL'
UPDATE invoice_documents d
JOIN invoices i ON i.id = d.invoice_id
SET d.invoice_revision_id = i.issued_revision_id
WHERE d.invoice_revision_id IS NULL
SQL);
        } finally {
            try {
                DB::connection($connection)->statement('SET @invoice_document_revision_backfill_token = NULL');
            } finally {
                $this->createImmutableDocumentGuard($connection);
            }
        }

        $this->assertDocumentRevisionLinks($connection);
    }

    private function assertDocumentRevisionLinks(string $connection): void
    {
        $invalid = DB::connection($connection)->selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM invoice_documents d
JOIN invoices i ON i.id = d.invoice_id
WHERE d.invoice_revision_id IS NULL
   OR i.issued_revision_id IS NULL
   OR d.invoice_revision_id <> i.issued_revision_id
SQL);
        if ((int) $invalid->aggregate !== 0) {
            throw new RuntimeException('PDF dokumenty nelze bezpečně navázat na aktuální issued revize.');
        }
    }

    private function ensureDocumentRevisionConstraints(string $connection): void
    {
        $column = DB::connection($connection)->selectOne(<<<'SQL'
SELECT IS_NULLABLE AS is_nullable
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'invoice_documents'
  AND COLUMN_NAME = 'invoice_revision_id'
SQL);
        if ($column === null) {
            throw new RuntimeException('Sloupec invoice_documents.invoice_revision_id nebyl vytvořen.');
        }
        if ($column->is_nullable === 'YES') {
            Schema::connection($connection)->table('invoice_documents', function (Blueprint $table): void {
                $table->unsignedBigInteger('invoice_revision_id')->nullable(false)->change();
            });
        }

        if (! $this->foreignKeyExists($connection, 'invoice_documents', self::DOCUMENT_REVISION_FOREIGN)) {
            Schema::connection($connection)->table('invoice_documents', function (Blueprint $table): void {
                $table->foreign(['invoice_revision_id', 'invoice_id'], self::DOCUMENT_REVISION_FOREIGN)
                    ->references(['id', 'invoice_id'])->on('invoice_revisions')->restrictOnUpdate()->restrictOnDelete();
            });
        }
        if (! $this->indexExists($connection, 'invoice_documents', self::DOCUMENT_REVISION_INDEX)) {
            Schema::connection($connection)->table('invoice_documents', function (Blueprint $table): void {
                $table->index(['invoice_id', 'invoice_revision_id', 'generated_at'], self::DOCUMENT_REVISION_INDEX);
            });
        }
    }

    private function ensureIssuedRevisionOperationsTable(string $connection): void
    {
        if (Schema::connection($connection)->hasTable('invoice_issued_revision_operations')) {
            return;
        }

        Schema::connection($connection)->create('invoice_issued_revision_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('correlation_uuid')->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('invoice_revision_id');
            $table->string('created_by_actor')->nullable();
            $table->timestamps(6);
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign(['invoice_revision_id', 'invoice_id'], 'invoice_issued_operations_revision_foreign')
                ->references(['id', 'invoice_id'])->on('invoice_revisions')->restrictOnUpdate()->restrictOnDelete();
        });
    }

    private function createDocumentBackfillGuard(string $connection, string $token): void
    {
        DB::connection($connection)->unprepared(<<<SQL
CREATE OR REPLACE TRIGGER `invoice_documents_immutable_update`
BEFORE UPDATE ON `invoice_documents` FOR EACH ROW
BEGIN
    IF NOT (
        BINARY COALESCE(@invoice_document_revision_backfill_token, '') = BINARY '{$token}'
        AND OLD.`id` <=> NEW.`id`
        AND OLD.`uuid` <=> NEW.`uuid`
        AND OLD.`invoice_id` <=> NEW.`invoice_id`
        AND OLD.`invoice_revision_id` IS NULL
        AND NEW.`invoice_revision_id` IS NOT NULL
        AND NEW.`invoice_revision_id` <=> (SELECT `issued_revision_id` FROM `invoices` WHERE `id` = NEW.`invoice_id`)
        AND OLD.`document_type` <=> NEW.`document_type`
        AND OLD.`storage_disk` <=> NEW.`storage_disk`
        AND OLD.`storage_path` <=> NEW.`storage_path`
        AND OLD.`original_filename` <=> NEW.`original_filename`
        AND OLD.`mime_type` <=> NEW.`mime_type`
        AND OLD.`size_bytes` <=> NEW.`size_bytes`
        AND OLD.`sha256` <=> NEW.`sha256`
        AND OLD.`template_version` <=> NEW.`template_version`
        AND OLD.`generated_at` <=> NEW.`generated_at`
        AND OLD.`generated_by_actor` <=> NEW.`generated_by_actor`
        AND OLD.`generation_correlation_uuid` <=> NEW.`generation_correlation_uuid`
        AND OLD.`created_at` <=> NEW.`created_at`
        AND OLD.`updated_at` <=> NEW.`updated_at`
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice document is immutable';
    END IF;
END
SQL);
    }

    private function createImmutableDocumentGuard(string $connection): void
    {
        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoice_documents_immutable_update`
BEFORE UPDATE ON `invoice_documents` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice document is immutable'
SQL);
    }

    private function foreignKeyExists(string $connection, string $table, string $constraint): bool
    {
        $result = DB::connection($connection)->selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND CONSTRAINT_NAME = ?
  AND CONSTRAINT_TYPE = 'FOREIGN KEY'
SQL, [$table, $constraint]);

        return (int) $result->aggregate === 1;
    }

    private function indexExists(string $connection, string $table, string $index): bool
    {
        $result = DB::connection($connection)->selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND INDEX_NAME = ?
SQL, [$table, $index]);

        return (int) $result->aggregate > 0;
    }

    private function replaceIssuedGuards(string $connection): void
    {

        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoice_revisions_issued_invoice_insert_guard`
BEFORE INSERT ON `invoice_revisions` FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM `invoices` WHERE `id` = NEW.`invoice_id` AND `status` = 'issued')
       AND NOT (
           BINARY COALESCE(@invoice_admin_operation, '') = BINARY 'revise'
           AND BINARY COALESCE(@invoice_admin_uuid, '') = BINARY COALESCE((SELECT `uuid` FROM `invoices` WHERE `id` = NEW.`invoice_id`), '')
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice cannot receive unguarded revision';
    END IF;
END
SQL);

        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoices_issued_immutable_update`
BEFORE UPDATE ON `invoices` FOR EACH ROW
BEGIN
    IF OLD.`status` = 'issued' THEN
        IF BINARY COALESCE(@invoice_admin_operation, '') = BINARY 'revise'
           AND BINARY COALESCE(@invoice_admin_uuid, '') = BINARY OLD.`uuid` THEN
            IF NOT (
                OLD.`uuid` <=> NEW.`uuid`
                AND OLD.`document_type` <=> NEW.`document_type`
                AND OLD.`status` <=> NEW.`status`
                AND OLD.`document_number` <=> NEW.`document_number`
                AND OLD.`document_sequence_id` <=> NEW.`document_sequence_id`
                AND OLD.`document_number_allocation_id` <=> NEW.`document_number_allocation_id`
                AND OLD.`issued_at` <=> NEW.`issued_at`
                AND OLD.`issue_correlation_uuid` <=> NEW.`issue_correlation_uuid`
                AND OLD.`archived_at` <=> NEW.`archived_at`
                AND OLD.`created_at` <=> NEW.`created_at`
                AND NEW.`version` = OLD.`version` + 1
                AND NEW.`current_revision_id` = NEW.`issued_revision_id`
                AND EXISTS (
                    SELECT 1 FROM `invoice_revisions` r
                    WHERE r.`id` = NEW.`issued_revision_id`
                      AND r.`invoice_id` = NEW.`id`
                      AND r.`currency` <=> NEW.`currency`
                      AND r.`issued_on` <=> NEW.`issued_on`
                      AND r.`taxable_supply_on` <=> NEW.`taxable_supply_on`
                      AND r.`due_on` <=> NEW.`due_on`
                      AND r.`payment_method` <=> NEW.`payment_method`
                      AND r.`variable_symbol` <=> NEW.`variable_symbol`
                      AND r.`note` <=> NEW.`note`
                )
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid issued invoice revision transition';
            END IF;
        ELSEIF BINARY COALESCE(@invoice_admin_operation, '') = BINARY 'archive'
           AND BINARY COALESCE(@invoice_admin_uuid, '') = BINARY OLD.`uuid` THEN
            IF NOT (
                OLD.`uuid` <=> NEW.`uuid`
                AND OLD.`document_type` <=> NEW.`document_type`
                AND OLD.`status` <=> NEW.`status`
                AND OLD.`document_number` <=> NEW.`document_number`
                AND OLD.`document_sequence_id` <=> NEW.`document_sequence_id`
                AND OLD.`document_number_allocation_id` <=> NEW.`document_number_allocation_id`
                AND OLD.`current_revision_id` <=> NEW.`current_revision_id`
                AND OLD.`issued_revision_id` <=> NEW.`issued_revision_id`
                AND OLD.`version` <=> NEW.`version`
                AND OLD.`issued_at` <=> NEW.`issued_at`
                AND OLD.`issue_correlation_uuid` <=> NEW.`issue_correlation_uuid`
                AND OLD.`currency` <=> NEW.`currency`
                AND OLD.`issued_on` <=> NEW.`issued_on`
                AND OLD.`taxable_supply_on` <=> NEW.`taxable_supply_on`
                AND OLD.`due_on` <=> NEW.`due_on`
                AND OLD.`payment_method` <=> NEW.`payment_method`
                AND OLD.`variable_symbol` <=> NEW.`variable_symbol`
                AND OLD.`note` <=> NEW.`note`
                AND OLD.`created_at` <=> NEW.`created_at`
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid issued invoice archive transition';
            END IF;
        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice is immutable';
        END IF;
    END IF;
END
SQL);
    }

    private function createLegacyIssuedGuards(string $connection): void
    {
        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoices_issued_immutable_update`
BEFORE UPDATE ON `invoices` FOR EACH ROW
BEGIN
    IF OLD.`status` = 'issued' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice is immutable';
    END IF;
END
SQL);
        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoice_revisions_issued_invoice_insert_guard`
BEFORE INSERT ON `invoice_revisions` FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM `invoices` WHERE `id` = NEW.`invoice_id` AND `status` = 'issued') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice cannot receive another revision';
    END IF;
END
SQL);
    }
};
