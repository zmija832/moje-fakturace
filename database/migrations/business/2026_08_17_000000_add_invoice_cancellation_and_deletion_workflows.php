<?php

use App\Enums\BusinessConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CANCELLATION_CORRELATION_UNIQUE = 'invoices_cancellation_correlation_unique';

    public function up(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();

        if (! Schema::connection($connection)->hasColumn('invoices', 'cancelled_at')) {
            Schema::connection($connection)->table('invoices', function (Blueprint $table): void {
                $table->timestamp('cancelled_at', 6)->nullable()->after('archived_at');
                $table->string('cancelled_by_actor')->nullable()->after('cancelled_at');
                $table->string('cancellation_reason', 255)->nullable()->after('cancelled_by_actor');
                $table->uuid('cancellation_correlation_uuid')->nullable()->after('cancellation_reason');
            });
        }
        if (! $this->indexExists($connection, 'invoices', self::CANCELLATION_CORRELATION_UNIQUE)) {
            Schema::connection($connection)->table('invoices', function (Blueprint $table): void {
                $table->unique('cancellation_correlation_uuid', self::CANCELLATION_CORRELATION_UNIQUE);
            });
        }

        $this->dropCheckIfExists($connection, 'invoices_issuance_values_check');
        if (! $this->checkExists($connection, 'invoices_cancellation_values_check')) {
            DB::connection($connection)->statement(<<<'SQL'
ALTER TABLE `invoices` ADD CONSTRAINT `invoices_cancellation_values_check`
CHECK (
    `document_type` = 'issued_invoice'
    AND `status` IN ('draft', 'issued', 'cancelled', 'purging')
    AND `due_on` >= `issued_on`
    AND (
        (`status` = 'draft'
            AND `document_number` IS NULL AND `document_sequence_id` IS NULL
            AND `document_number_allocation_id` IS NULL AND `issued_revision_id` IS NULL
            AND `issued_at` IS NULL AND `issue_correlation_uuid` IS NULL
            AND `cancelled_at` IS NULL AND `cancelled_by_actor` IS NULL
            AND `cancellation_reason` IS NULL AND `cancellation_correlation_uuid` IS NULL)
        OR
        (`status` = 'issued'
            AND `document_number` IS NOT NULL AND `document_sequence_id` IS NOT NULL
            AND `document_number_allocation_id` IS NOT NULL AND `issued_revision_id` IS NOT NULL
            AND `current_revision_id` = `issued_revision_id`
            AND `issued_at` IS NOT NULL AND `issue_correlation_uuid` IS NOT NULL
            AND `cancelled_at` IS NULL AND `cancelled_by_actor` IS NULL
            AND `cancellation_reason` IS NULL AND `cancellation_correlation_uuid` IS NULL)
        OR
        (`status` = 'cancelled'
            AND `document_number` IS NOT NULL AND `document_sequence_id` IS NOT NULL
            AND `document_number_allocation_id` IS NOT NULL AND `issued_revision_id` IS NOT NULL
            AND `current_revision_id` = `issued_revision_id`
            AND `issued_at` IS NOT NULL AND `issue_correlation_uuid` IS NOT NULL
            AND `cancelled_at` IS NOT NULL AND `cancelled_by_actor` IS NOT NULL
            AND `cancellation_reason` IS NOT NULL AND `cancellation_correlation_uuid` IS NOT NULL)
        OR
        (`status` = 'purging'
            AND `document_number` IS NOT NULL AND `document_sequence_id` IS NOT NULL
            AND `document_number_allocation_id` IS NOT NULL
            AND `current_revision_id` IS NULL AND `issued_revision_id` IS NULL
            AND `issued_at` IS NOT NULL AND `issue_correlation_uuid` IS NOT NULL)
    )
)
SQL);
        }

        $this->createInvoiceGuards($connection);
        $this->createAggregateDeleteGuards($connection);
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        if (DB::connection($connection)->table('invoices')->where('status', 'cancelled')->exists()) {
            throw new RuntimeException('Migraci storna nelze vrátit, pokud existují stornované faktury.');
        }

        $this->dropCheckIfExists($connection, 'invoices_cancellation_values_check');
        DB::connection($connection)->statement(<<<'SQL'
ALTER TABLE `invoices` ADD CONSTRAINT `invoices_issuance_values_check`
CHECK (
    `document_type` = 'issued_invoice' AND `status` IN ('draft', 'issued') AND `due_on` >= `issued_on`
    AND (
        (`status` = 'draft' AND `document_number` IS NULL AND `document_sequence_id` IS NULL
            AND `document_number_allocation_id` IS NULL AND `issued_revision_id` IS NULL
            AND `issued_at` IS NULL AND `issue_correlation_uuid` IS NULL)
        OR
        (`status` = 'issued' AND `document_number` IS NOT NULL AND `document_sequence_id` IS NOT NULL
            AND `document_number_allocation_id` IS NOT NULL AND `issued_revision_id` IS NOT NULL
            AND `current_revision_id` = `issued_revision_id`
            AND `issued_at` IS NOT NULL AND `issue_correlation_uuid` IS NOT NULL)
    )
)
SQL);
        $this->createLegacyInvoiceGuards($connection);
        $this->createLegacyDeleteGuards($connection);

        Schema::connection($connection)->table('invoices', function (Blueprint $table): void {
            $table->dropUnique(self::CANCELLATION_CORRELATION_UNIQUE);
            $table->dropColumn([
                'cancelled_at', 'cancelled_by_actor', 'cancellation_reason', 'cancellation_correlation_uuid',
            ]);
        });
    }

    private function createInvoiceGuards(string $connection): void
    {
        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoice_revisions_issued_invoice_insert_guard`
BEFORE INSERT ON `invoice_revisions` FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM `invoices` WHERE `id` = NEW.`invoice_id` AND `status` IN ('issued', 'cancelled', 'purging'))
       AND NOT (
           BINARY COALESCE(@invoice_admin_operation, '') = BINARY 'revise'
           AND BINARY COALESCE(@invoice_admin_uuid, '') = BINARY COALESCE((SELECT `uuid` FROM `invoices` WHERE `id` = NEW.`invoice_id` AND `status` = 'issued'), '')
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice cannot receive unguarded revision';
    END IF;
END
SQL);

        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoices_issued_immutable_update`
BEFORE UPDATE ON `invoices` FOR EACH ROW
BEGIN
    IF OLD.`status` IN ('issued', 'cancelled') THEN
        IF BINARY COALESCE(@invoice_admin_operation, '') = BINARY 'revise'
           AND BINARY COALESCE(@invoice_admin_uuid, '') = BINARY OLD.`uuid`
           AND OLD.`status` = 'issued' THEN
            IF NOT (
                OLD.`uuid` <=> NEW.`uuid` AND OLD.`document_type` <=> NEW.`document_type`
                AND OLD.`status` <=> NEW.`status` AND OLD.`document_number` <=> NEW.`document_number`
                AND OLD.`document_sequence_id` <=> NEW.`document_sequence_id`
                AND OLD.`document_number_allocation_id` <=> NEW.`document_number_allocation_id`
                AND OLD.`issued_at` <=> NEW.`issued_at` AND OLD.`issue_correlation_uuid` <=> NEW.`issue_correlation_uuid`
                AND OLD.`archived_at` <=> NEW.`archived_at` AND OLD.`cancelled_at` <=> NEW.`cancelled_at`
                AND OLD.`cancelled_by_actor` <=> NEW.`cancelled_by_actor`
                AND OLD.`cancellation_reason` <=> NEW.`cancellation_reason`
                AND OLD.`cancellation_correlation_uuid` <=> NEW.`cancellation_correlation_uuid`
                AND OLD.`created_at` <=> NEW.`created_at` AND NEW.`version` = OLD.`version` + 1
                AND NEW.`current_revision_id` = NEW.`issued_revision_id`
                AND EXISTS (
                    SELECT 1 FROM `invoice_revisions` r
                    WHERE r.`id` = NEW.`issued_revision_id` AND r.`invoice_id` = NEW.`id`
                      AND r.`currency` <=> NEW.`currency` AND r.`issued_on` <=> NEW.`issued_on`
                      AND r.`taxable_supply_on` <=> NEW.`taxable_supply_on` AND r.`due_on` <=> NEW.`due_on`
                      AND r.`payment_method` <=> NEW.`payment_method`
                      AND r.`variable_symbol` <=> NEW.`variable_symbol` AND r.`note` <=> NEW.`note`
                )
            ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid issued invoice revision transition'; END IF;
        ELSEIF BINARY COALESCE(@invoice_admin_operation, '') = BINARY 'archive'
           AND BINARY COALESCE(@invoice_admin_uuid, '') = BINARY OLD.`uuid` THEN
            IF NOT (
                OLD.`uuid` <=> NEW.`uuid` AND OLD.`document_type` <=> NEW.`document_type`
                AND OLD.`status` <=> NEW.`status` AND OLD.`document_number` <=> NEW.`document_number`
                AND OLD.`document_sequence_id` <=> NEW.`document_sequence_id`
                AND OLD.`document_number_allocation_id` <=> NEW.`document_number_allocation_id`
                AND OLD.`current_revision_id` <=> NEW.`current_revision_id`
                AND OLD.`issued_revision_id` <=> NEW.`issued_revision_id` AND OLD.`version` <=> NEW.`version`
                AND OLD.`issued_at` <=> NEW.`issued_at` AND OLD.`issue_correlation_uuid` <=> NEW.`issue_correlation_uuid`
                AND OLD.`currency` <=> NEW.`currency` AND OLD.`issued_on` <=> NEW.`issued_on`
                AND OLD.`taxable_supply_on` <=> NEW.`taxable_supply_on` AND OLD.`due_on` <=> NEW.`due_on`
                AND OLD.`payment_method` <=> NEW.`payment_method` AND OLD.`variable_symbol` <=> NEW.`variable_symbol`
                AND OLD.`note` <=> NEW.`note` AND OLD.`cancelled_at` <=> NEW.`cancelled_at`
                AND OLD.`cancelled_by_actor` <=> NEW.`cancelled_by_actor`
                AND OLD.`cancellation_reason` <=> NEW.`cancellation_reason`
                AND OLD.`cancellation_correlation_uuid` <=> NEW.`cancellation_correlation_uuid`
                AND OLD.`created_at` <=> NEW.`created_at`
            ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid issued invoice archive transition'; END IF;
        ELSEIF BINARY COALESCE(@invoice_admin_operation, '') = BINARY 'cancel'
           AND BINARY COALESCE(@invoice_admin_uuid, '') = BINARY OLD.`uuid`
           AND BINARY COALESCE(@invoice_admin_correlation, '') = BINARY NEW.`cancellation_correlation_uuid` THEN
            IF NOT (
                OLD.`status` = 'issued' AND NEW.`status` = 'cancelled'
                AND OLD.`uuid` <=> NEW.`uuid` AND OLD.`document_type` <=> NEW.`document_type`
                AND OLD.`document_number` <=> NEW.`document_number`
                AND OLD.`document_sequence_id` <=> NEW.`document_sequence_id`
                AND OLD.`document_number_allocation_id` <=> NEW.`document_number_allocation_id`
                AND OLD.`current_revision_id` <=> NEW.`current_revision_id`
                AND OLD.`issued_revision_id` <=> NEW.`issued_revision_id` AND OLD.`version` <=> NEW.`version`
                AND OLD.`issued_at` <=> NEW.`issued_at` AND OLD.`issue_correlation_uuid` <=> NEW.`issue_correlation_uuid`
                AND OLD.`currency` <=> NEW.`currency` AND OLD.`issued_on` <=> NEW.`issued_on`
                AND OLD.`taxable_supply_on` <=> NEW.`taxable_supply_on` AND OLD.`due_on` <=> NEW.`due_on`
                AND OLD.`payment_method` <=> NEW.`payment_method` AND OLD.`variable_symbol` <=> NEW.`variable_symbol`
                AND OLD.`note` <=> NEW.`note` AND OLD.`archived_at` <=> NEW.`archived_at`
                AND OLD.`created_at` <=> NEW.`created_at` AND NEW.`cancelled_at` IS NOT NULL
                AND NEW.`cancelled_by_actor` IS NOT NULL AND NEW.`cancellation_reason` IS NOT NULL
                AND NEW.`cancellation_correlation_uuid` IS NOT NULL
            ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid invoice cancellation transition'; END IF;
        ELSEIF BINARY COALESCE(@invoice_destructive_operation, '') = BINARY 'test_purge'
           AND BINARY COALESCE(@invoice_destructive_uuid, '') = BINARY OLD.`uuid` THEN
            IF NOT (
                NEW.`status` = 'purging' AND OLD.`uuid` <=> NEW.`uuid`
                AND OLD.`document_type` <=> NEW.`document_type` AND OLD.`document_number` <=> NEW.`document_number`
                AND OLD.`document_sequence_id` <=> NEW.`document_sequence_id`
                AND OLD.`document_number_allocation_id` <=> NEW.`document_number_allocation_id`
                AND NEW.`current_revision_id` IS NULL AND NEW.`issued_revision_id` IS NULL
                AND OLD.`version` <=> NEW.`version` AND OLD.`issued_at` <=> NEW.`issued_at`
                AND OLD.`issue_correlation_uuid` <=> NEW.`issue_correlation_uuid`
                AND OLD.`currency` <=> NEW.`currency` AND OLD.`issued_on` <=> NEW.`issued_on`
                AND OLD.`taxable_supply_on` <=> NEW.`taxable_supply_on` AND OLD.`due_on` <=> NEW.`due_on`
                AND OLD.`payment_method` <=> NEW.`payment_method` AND OLD.`variable_symbol` <=> NEW.`variable_symbol`
                AND OLD.`note` <=> NEW.`note` AND OLD.`archived_at` <=> NEW.`archived_at`
                AND OLD.`cancelled_at` <=> NEW.`cancelled_at`
                AND OLD.`cancelled_by_actor` <=> NEW.`cancelled_by_actor`
                AND OLD.`cancellation_reason` <=> NEW.`cancellation_reason`
                AND OLD.`cancellation_correlation_uuid` <=> NEW.`cancellation_correlation_uuid`
                AND OLD.`created_at` <=> NEW.`created_at`
            ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid invoice purge transition'; END IF;
        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice is immutable';
        END IF;
    END IF;
END
SQL);

        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoices_issued_immutable_delete`
BEFORE DELETE ON `invoices` FOR EACH ROW
BEGIN
    IF OLD.`status` IN ('issued', 'cancelled', 'purging')
       AND NOT (OLD.`status` = 'purging'
           AND BINARY COALESCE(@invoice_destructive_operation, '') = BINARY 'test_purge'
           AND BINARY COALESCE(@invoice_destructive_uuid, '') = BINARY OLD.`uuid`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice cannot be deleted';
    END IF;
END
SQL);
    }

    private function createAggregateDeleteGuards(string $connection): void
    {
        $direct = [
            'invoice_revisions' => 'OLD.`invoice_id`',
            'invoice_draft_operations' => 'OLD.`invoice_id`',
            'invoice_documents' => 'OLD.`invoice_id`',
        ];
        foreach ($direct as $table => $invoiceId) {
            DB::connection($connection)->unprepared(<<<SQL
CREATE OR REPLACE TRIGGER `{$table}_immutable_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM `invoices` i WHERE i.`id` = {$invoiceId}
          AND BINARY i.`uuid` = BINARY COALESCE(@invoice_destructive_uuid, '')
          AND ((BINARY COALESCE(@invoice_destructive_operation, '') = BINARY 'draft_delete' AND i.`status` = 'draft')
            OR (BINARY COALESCE(@invoice_destructive_operation, '') = BINARY 'test_purge' AND i.`status` = 'purging'))
    ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice aggregate record is immutable'; END IF;
END
SQL);
        }

        foreach (['invoice_supplier_snapshots', 'invoice_customer_snapshots', 'invoice_bank_account_snapshots', 'invoice_vat_snapshots', 'invoice_items', 'invoice_vat_summaries'] as $table) {
            DB::connection($connection)->unprepared(<<<SQL
CREATE OR REPLACE TRIGGER `{$table}_immutable_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM `invoice_revisions` r JOIN `invoices` i ON i.`id` = r.`invoice_id`
        WHERE r.`id` = OLD.`invoice_revision_id`
          AND BINARY i.`uuid` = BINARY COALESCE(@invoice_destructive_uuid, '')
          AND ((BINARY COALESCE(@invoice_destructive_operation, '') = BINARY 'draft_delete' AND i.`status` = 'draft')
            OR (BINARY COALESCE(@invoice_destructive_operation, '') = BINARY 'test_purge' AND i.`status` = 'purging'))
    ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice revision record is immutable'; END IF;
END
SQL);
        }
    }

    private function createLegacyInvoiceGuards(string $connection): void
    {
        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoices_issued_immutable_update`
BEFORE UPDATE ON `invoices` FOR EACH ROW
BEGIN
    IF OLD.`status` = 'issued' THEN
        IF BINARY COALESCE(@invoice_admin_operation, '') = BINARY 'revise'
           AND BINARY COALESCE(@invoice_admin_uuid, '') = BINARY OLD.`uuid` THEN
            IF NOT (
                OLD.`uuid` <=> NEW.`uuid` AND OLD.`document_type` <=> NEW.`document_type`
                AND OLD.`status` <=> NEW.`status` AND OLD.`document_number` <=> NEW.`document_number`
                AND OLD.`document_sequence_id` <=> NEW.`document_sequence_id`
                AND OLD.`document_number_allocation_id` <=> NEW.`document_number_allocation_id`
                AND OLD.`issued_at` <=> NEW.`issued_at` AND OLD.`issue_correlation_uuid` <=> NEW.`issue_correlation_uuid`
                AND OLD.`archived_at` <=> NEW.`archived_at` AND OLD.`created_at` <=> NEW.`created_at`
                AND NEW.`version` = OLD.`version` + 1 AND NEW.`current_revision_id` = NEW.`issued_revision_id`
                AND EXISTS (SELECT 1 FROM `invoice_revisions` r
                    WHERE r.`id` = NEW.`issued_revision_id` AND r.`invoice_id` = NEW.`id`
                      AND r.`currency` <=> NEW.`currency` AND r.`issued_on` <=> NEW.`issued_on`
                      AND r.`taxable_supply_on` <=> NEW.`taxable_supply_on` AND r.`due_on` <=> NEW.`due_on`
                      AND r.`payment_method` <=> NEW.`payment_method`
                      AND r.`variable_symbol` <=> NEW.`variable_symbol` AND r.`note` <=> NEW.`note`)
            ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid issued invoice revision transition'; END IF;
        ELSEIF BINARY COALESCE(@invoice_admin_operation, '') = BINARY 'archive'
           AND BINARY COALESCE(@invoice_admin_uuid, '') = BINARY OLD.`uuid` THEN
            IF NOT (
                OLD.`uuid` <=> NEW.`uuid` AND OLD.`document_type` <=> NEW.`document_type`
                AND OLD.`status` <=> NEW.`status` AND OLD.`document_number` <=> NEW.`document_number`
                AND OLD.`document_sequence_id` <=> NEW.`document_sequence_id`
                AND OLD.`document_number_allocation_id` <=> NEW.`document_number_allocation_id`
                AND OLD.`current_revision_id` <=> NEW.`current_revision_id`
                AND OLD.`issued_revision_id` <=> NEW.`issued_revision_id` AND OLD.`version` <=> NEW.`version`
                AND OLD.`issued_at` <=> NEW.`issued_at` AND OLD.`issue_correlation_uuid` <=> NEW.`issue_correlation_uuid`
                AND OLD.`currency` <=> NEW.`currency` AND OLD.`issued_on` <=> NEW.`issued_on`
                AND OLD.`taxable_supply_on` <=> NEW.`taxable_supply_on` AND OLD.`due_on` <=> NEW.`due_on`
                AND OLD.`payment_method` <=> NEW.`payment_method` AND OLD.`variable_symbol` <=> NEW.`variable_symbol`
                AND OLD.`note` <=> NEW.`note` AND OLD.`created_at` <=> NEW.`created_at`
            ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid issued invoice archive transition'; END IF;
        ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice is immutable'; END IF;
    END IF;
END
SQL);
        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoices_issued_immutable_delete` BEFORE DELETE ON `invoices` FOR EACH ROW
BEGIN IF OLD.`status` = 'issued' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice cannot be deleted'; END IF; END
SQL);
        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoice_revisions_issued_invoice_insert_guard` BEFORE INSERT ON `invoice_revisions` FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM `invoices` WHERE `id` = NEW.`invoice_id` AND `status` = 'issued')
       AND NOT (BINARY COALESCE(@invoice_admin_operation, '') = BINARY 'revise'
         AND BINARY COALESCE(@invoice_admin_uuid, '') = BINARY COALESCE((SELECT `uuid` FROM `invoices` WHERE `id` = NEW.`invoice_id`), ''))
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice cannot receive unguarded revision'; END IF;
END
SQL);
    }

    private function createLegacyDeleteGuards(string $connection): void
    {
        foreach (['invoice_revisions', 'invoice_supplier_snapshots', 'invoice_customer_snapshots', 'invoice_bank_account_snapshots', 'invoice_vat_snapshots', 'invoice_items', 'invoice_vat_summaries', 'invoice_draft_operations'] as $table) {
            DB::connection($connection)->unprepared("CREATE OR REPLACE TRIGGER `{$table}_immutable_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice revision record is immutable'");
        }
        DB::connection($connection)->unprepared("CREATE OR REPLACE TRIGGER `invoice_documents_immutable_delete` BEFORE DELETE ON `invoice_documents` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice document cannot be deleted'");
    }

    private function dropCheckIfExists(string $connection, string $constraint): void
    {
        if (! $this->checkExists($connection, $constraint)) {
            return;
        }
        $database = DB::connection($connection);
        $serverVersion = strtolower((string) $database->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION));
        $dropClause = str_contains($serverVersion, 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK';
        $database->statement("ALTER TABLE `invoices` {$dropClause} `{$constraint}`");
    }

    private function checkExists(string $connection, string $constraint): bool
    {
        $row = DB::connection($connection)->selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
  AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'
SQL, [$constraint]);

        return (int) $row->aggregate === 1;
    }

    private function indexExists(string $connection, string $table, string $index): bool
    {
        $row = DB::connection($connection)->selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
SQL, [$table, $index]);

        return (int) $row->aggregate > 0;
    }
};
