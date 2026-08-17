<?php

use App\Enums\BusinessConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();

        Schema::connection($connection)->table('invoice_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_revision_id')->nullable()->after('invoice_id');
        });
        DB::connection($connection)->statement(<<<'SQL'
UPDATE invoice_documents d
JOIN invoices i ON i.id = d.invoice_id
SET d.invoice_revision_id = i.issued_revision_id
WHERE d.invoice_revision_id IS NULL
SQL);
        Schema::connection($connection)->table('invoice_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_revision_id')->nullable(false)->change();
            $table->foreign(['invoice_revision_id', 'invoice_id'], 'invoice_documents_revision_invoice_foreign')
                ->references(['id', 'invoice_id'])->on('invoice_revisions')->restrictOnUpdate()->restrictOnDelete();
            $table->index(['invoice_id', 'invoice_revision_id', 'generated_at'], 'invoice_documents_revision_latest_index');
        });

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

        $this->replaceIssuedGuards($connection);
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        if (Schema::connection($connection)->hasTable('invoice_issued_revision_operations')
            && DB::connection($connection)->table('invoice_issued_revision_operations')->exists()) {
            throw new RuntimeException('Workflow admin úprav vystavených faktur nelze vrátit, pokud existují jeho operace.');
        }

        DB::connection($connection)->unprepared('DROP TRIGGER IF EXISTS `invoices_issued_immutable_update`');
        DB::connection($connection)->unprepared('DROP TRIGGER IF EXISTS `invoice_revisions_issued_invoice_insert_guard`');
        $this->createLegacyIssuedGuards($connection);

        Schema::connection($connection)->dropIfExists('invoice_issued_revision_operations');
        Schema::connection($connection)->table('invoice_documents', function (Blueprint $table): void {
            $table->dropForeign('invoice_documents_revision_invoice_foreign');
            $table->dropIndex('invoice_documents_revision_latest_index');
            $table->dropColumn('invoice_revision_id');
        });
    }

    private function replaceIssuedGuards(string $connection): void
    {
        DB::connection($connection)->unprepared('DROP TRIGGER IF EXISTS `invoices_issued_immutable_update`');
        DB::connection($connection)->unprepared('DROP TRIGGER IF EXISTS `invoice_revisions_issued_invoice_insert_guard`');

        DB::connection($connection)->unprepared(<<<'SQL'
CREATE TRIGGER `invoice_revisions_issued_invoice_insert_guard`
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
CREATE TRIGGER `invoices_issued_immutable_update`
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
CREATE TRIGGER `invoices_issued_immutable_update`
BEFORE UPDATE ON `invoices` FOR EACH ROW
BEGIN
    IF OLD.`status` = 'issued' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice is immutable';
    END IF;
END
SQL);
        DB::connection($connection)->unprepared(<<<'SQL'
CREATE TRIGGER `invoice_revisions_issued_invoice_insert_guard`
BEFORE INSERT ON `invoice_revisions` FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM `invoices` WHERE `id` = NEW.`invoice_id` AND `status` = 'issued') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice cannot receive another revision';
    END IF;
END
SQL);
    }
};
