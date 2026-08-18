<?php

use App\Enums\BusinessConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        $this->replaceOperationDiscriminator($connection, 'test_purge', 'invoice_delete');

        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoice_payments_immutable_delete` BEFORE DELETE ON `invoice_payments` FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM `invoices` i WHERE i.`id` = OLD.`invoice_id` AND i.`status` = 'purging'
        AND BINARY i.`uuid` = BINARY COALESCE(@invoice_destructive_uuid, '')
        AND BINARY COALESCE(@invoice_destructive_operation, '') = BINARY 'invoice_delete')
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice payment cannot be deleted'; END IF;
END
SQL);
        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoice_deliveries_immutable_delete` BEFORE DELETE ON `invoice_email_deliveries` FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM `invoices` i WHERE i.`id` = OLD.`invoice_id` AND i.`status` = 'purging'
        AND BINARY i.`uuid` = BINARY COALESCE(@invoice_destructive_uuid, '')
        AND BINARY COALESCE(@invoice_destructive_operation, '') = BINARY 'invoice_delete')
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice delivery cannot be deleted'; END IF;
END
SQL);
        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `invoice_issued_operations_immutable_delete` BEFORE DELETE ON `invoice_issued_revision_operations` FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM `invoices` i WHERE i.`id` = OLD.`invoice_id` AND i.`status` = 'purging'
        AND BINARY i.`uuid` = BINARY COALESCE(@invoice_destructive_uuid, '')
        AND BINARY COALESCE(@invoice_destructive_operation, '') = BINARY 'invoice_delete')
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued revision operation cannot be deleted'; END IF;
END
SQL);
        DB::connection($connection)->unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER `document_allocations_immutable_delete` BEFORE DELETE ON `document_number_allocations` FOR EACH ROW
BEGIN
    IF NOT (BINARY COALESCE(@invoice_destructive_operation, '') = BINARY 'invoice_delete'
        AND BINARY OLD.`document_uuid` = BINARY COALESCE(@invoice_destructive_uuid, '')
        AND NOT EXISTS (SELECT 1 FROM `invoices` i WHERE BINARY i.`uuid` = BINARY OLD.`document_uuid`))
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document number allocation cannot be deleted'; END IF;
END
SQL);
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        DB::connection($connection)->unprepared("CREATE OR REPLACE TRIGGER `invoice_payments_immutable_delete` BEFORE DELETE ON `invoice_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice payment cannot be deleted'");
        DB::connection($connection)->unprepared("CREATE OR REPLACE TRIGGER `invoice_deliveries_immutable_delete` BEFORE DELETE ON `invoice_email_deliveries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice delivery cannot be deleted'");
        DB::connection($connection)->unprepared('DROP TRIGGER IF EXISTS `invoice_issued_operations_immutable_delete`');
        DB::connection($connection)->unprepared("CREATE OR REPLACE TRIGGER `document_allocations_immutable_delete` BEFORE DELETE ON `document_number_allocations` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document number allocation cannot be deleted'");
        $this->replaceOperationDiscriminator($connection, 'invoice_delete', 'test_purge');
    }

    private function replaceOperationDiscriminator(string $connection, string $from, string $to): void
    {
        $triggers = DB::connection($connection)->select(
            'SELECT TRIGGER_NAME AS name, EVENT_MANIPULATION AS event_name, EVENT_OBJECT_TABLE AS table_name,
                    ACTION_TIMING AS timing, ACTION_STATEMENT AS body
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() AND ACTION_STATEMENT LIKE ?',
            ["%'{$from}'%"],
        );

        foreach ($triggers as $trigger) {
            $timing = strtoupper((string) $trigger->timing);
            $event = strtoupper((string) $trigger->event_name);
            if (! in_array($timing, ['BEFORE', 'AFTER'], true)
                || ! in_array($event, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                throw new RuntimeException('Neočekávaná definice databázového triggeru.');
            }
            $name = str_replace('`', '``', (string) $trigger->name);
            $table = str_replace('`', '``', (string) $trigger->table_name);
            $body = str_replace("'{$from}'", "'{$to}'", (string) $trigger->body);
            DB::connection($connection)->unprepared(
                "CREATE OR REPLACE TRIGGER `{$name}` {$timing} {$event} ON `{$table}` FOR EACH ROW {$body}",
            );
        }
    }
};
