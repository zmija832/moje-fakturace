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

        $this->dropInvoiceCheckConstraint($connection, 'invoices_part_one_values_check');

        Schema::connection($connection)->table('invoice_revisions', function (Blueprint $table): void {
            $table->unique(['id', 'invoice_id'], 'invoice_revisions_id_invoice_unique');
        });
        Schema::connection($connection)->table('document_number_allocations', function (Blueprint $table): void {
            $table->dropIndex('document_number_allocations_document_uuid_index');
            $table->unique(['id', 'document_sequence_id'], 'document_allocations_id_sequence_unique');
            $table->unique(
                ['id', 'document_sequence_id', 'document_type', 'formatted_number', 'document_uuid'],
                'document_allocations_invoice_link_unique',
            );
            $table->unique('document_uuid', 'document_allocations_document_uuid_unique');
        });

        Schema::connection($connection)->table('invoices', function (Blueprint $table): void {
            $table->string('document_number')->nullable()->unique()->after('document_type');
            $table->unsignedBigInteger('document_sequence_id')->nullable()->after('document_number');
            $table->unsignedBigInteger('document_number_allocation_id')->nullable()->unique()->after('document_sequence_id');
            $table->unsignedBigInteger('issued_revision_id')->nullable()->after('current_revision_id');
            $table->timestamp('issued_at', 6)->nullable()->after('version');
            $table->uuid('issue_correlation_uuid')->nullable()->unique()->after('issued_at');

            $table->foreign('document_sequence_id', 'invoices_document_sequence_foreign')
                ->references('id')->on('document_sequences')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign(
                ['document_number_allocation_id', 'document_sequence_id'],
                'invoices_allocation_sequence_foreign',
            )->references(['id', 'document_sequence_id'])->on('document_number_allocations')
                ->restrictOnUpdate()->restrictOnDelete();
            $table->foreign(
                ['document_number_allocation_id', 'document_sequence_id', 'document_type', 'document_number', 'uuid'],
                'invoices_allocation_link_foreign',
            )->references(['id', 'document_sequence_id', 'document_type', 'formatted_number', 'document_uuid'])
                ->on('document_number_allocations')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign(['issued_revision_id', 'id'], 'invoices_issued_revision_foreign')
                ->references(['id', 'invoice_id'])->on('invoice_revisions')->restrictOnUpdate()->restrictOnDelete();
        });

        $this->createConstraintsAndTriggers($connection);
    }

    private function createConstraintsAndTriggers(string $connection): void
    {
        $this->createInvoiceConstraint($connection);
        $this->createImmutableTriggers($connection);
    }

    private function createInvoiceConstraint(string $connection): void
    {
        DB::connection($connection)->statement(<<<'SQL'
            ALTER TABLE `invoices` ADD CONSTRAINT `invoices_issuance_values_check`
            CHECK (
                `document_type` = 'issued_invoice'
                AND `status` IN ('draft', 'issued')
                AND `due_on` >= `issued_on`
                AND (
                    (`status` = 'draft'
                        AND `document_number` IS NULL
                        AND `document_sequence_id` IS NULL
                        AND `document_number_allocation_id` IS NULL
                        AND `issued_revision_id` IS NULL
                        AND `issued_at` IS NULL
                        AND `issue_correlation_uuid` IS NULL)
                    OR
                    (`status` = 'issued'
                        AND `document_number` IS NOT NULL
                        AND `document_sequence_id` IS NOT NULL
                        AND `document_number_allocation_id` IS NOT NULL
                        AND `issued_revision_id` IS NOT NULL
                        AND `current_revision_id` = `issued_revision_id`
                        AND `issued_at` IS NOT NULL
                        AND `issue_correlation_uuid` IS NOT NULL)
                )
            )
            SQL);
    }

    private function createImmutableTriggers(string $connection): void
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
            CREATE TRIGGER `invoices_issued_immutable_delete`
            BEFORE DELETE ON `invoices` FOR EACH ROW
            BEGIN
                IF OLD.`status` = 'issued' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued invoice cannot be deleted';
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
        DB::connection($connection)->unprepared(<<<'SQL'
            CREATE TRIGGER `document_allocations_immutable_update`
            BEFORE UPDATE ON `document_number_allocations` FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document number allocation is immutable'
            SQL);
        DB::connection($connection)->unprepared(<<<'SQL'
            CREATE TRIGGER `document_allocations_immutable_delete`
            BEFORE DELETE ON `document_number_allocations` FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document number allocation cannot be deleted'
            SQL);
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();

        if (DB::connection($connection)->table('invoices')->where('status', 'issued')->exists()) {
            throw new RuntimeException('Migraci vystavování nelze vrátit, pokud existují vystavené faktury.');
        }

        foreach ([
            'invoices_issued_immutable_update', 'invoices_issued_immutable_delete',
            'invoice_revisions_issued_invoice_insert_guard', 'document_allocations_immutable_update',
            'document_allocations_immutable_delete',
        ] as $trigger) {
            DB::connection($connection)->unprepared('DROP TRIGGER IF EXISTS `'.$trigger.'`');
        }

        $this->dropInvoiceCheckConstraint($connection, 'invoices_issuance_values_check');
        $this->dropIssuanceColumns($connection);
        $this->restoreAllocationIndexes($connection);
        $this->restoreDraftConstraint($connection);
    }

    private function dropIssuanceColumns(string $connection): void
    {
        Schema::connection($connection)->table('invoices', function (Blueprint $table): void {
            $table->dropForeign('invoices_issued_revision_foreign');
            $table->dropForeign('invoices_allocation_link_foreign');
            $table->dropForeign('invoices_allocation_sequence_foreign');
            $table->dropForeign('invoices_document_sequence_foreign');
            $table->dropIndex('invoices_issued_revision_foreign');
            $table->dropIndex('invoices_allocation_link_foreign');
            $table->dropIndex('invoices_document_sequence_foreign');
            $table->dropUnique(['issue_correlation_uuid']);
            $table->dropUnique(['document_number_allocation_id']);
            $table->dropUnique(['document_number']);
            $table->dropColumn([
                'document_number', 'document_sequence_id', 'document_number_allocation_id',
                'issued_revision_id', 'issued_at', 'issue_correlation_uuid',
            ]);
        });
    }

    private function restoreAllocationIndexes(string $connection): void
    {
        Schema::connection($connection)->table('document_number_allocations', function (Blueprint $table): void {
            $table->dropUnique('document_allocations_document_uuid_unique');
            $table->dropUnique('document_allocations_invoice_link_unique');
            $table->dropUnique('document_allocations_id_sequence_unique');
            $table->index('document_uuid');
        });
        Schema::connection($connection)->table('invoice_revisions', function (Blueprint $table): void {
            $table->dropUnique('invoice_revisions_id_invoice_unique');
        });
    }

    private function restoreDraftConstraint(string $connection): void
    {
        DB::connection($connection)->statement(<<<'SQL'
            ALTER TABLE `invoices` ADD CONSTRAINT `invoices_part_one_values_check`
            CHECK (`document_type` = 'issued_invoice' AND `status` = 'draft' AND `due_on` >= `issued_on`)
            SQL);
    }

    private function dropInvoiceCheckConstraint(string $connection, string $constraint): void
    {
        $database = DB::connection($connection);
        $serverVersion = strtolower((string) $database->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION));
        $dropClause = str_contains($serverVersion, 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK';

        $database->statement("ALTER TABLE `invoices` {$dropClause} `{$constraint}`");
    }
};
