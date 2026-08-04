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

        Schema::connection($connection)->create('invoice_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->string('document_type', 32);
            $table->string('storage_disk', 64);
            $table->string('storage_path', 1024);
            $table->string('original_filename');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('template_version', 64);
            $table->timestamp('generated_at', 6);
            $table->string('generated_by_actor')->nullable();
            $table->uuid('generation_correlation_uuid')->unique();
            $table->timestamps(6);
            $table->unique(['id', 'invoice_id'], 'invoice_documents_id_invoice_unique');
            $table->index(['invoice_id', 'document_type', 'generated_at'], 'invoice_documents_invoice_latest_index');
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
        });
        DB::connection($connection)->statement(
            "ALTER TABLE `invoice_documents` ADD CONSTRAINT `invoice_documents_values_check` CHECK (`document_type` = 'invoice_pdf' AND `storage_disk` = 'invoice_documents' AND `mime_type` = 'application/pdf' AND `size_bytes` > 0)",
        );

        Schema::connection($connection)->create('invoice_email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('invoice_document_id');
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('subject');
            $table->text('body_text');
            $table->longText('body_html')->nullable();
            $table->string('status', 16);
            $table->string('provider_message_id')->nullable();
            $table->uuid('send_correlation_uuid')->unique();
            $table->timestamp('attempted_at', 6);
            $table->timestamp('sent_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 512)->nullable();
            $table->string('created_by_actor')->nullable();
            $table->timestamps(6);
            $table->index(['invoice_id', 'attempted_at'], 'invoice_deliveries_invoice_history_index');
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign(['invoice_document_id', 'invoice_id'], 'invoice_deliveries_document_invoice_foreign')
                ->references(['id', 'invoice_id'])->on('invoice_documents')->restrictOnUpdate()->restrictOnDelete();
        });
        DB::connection($connection)->statement(
            "ALTER TABLE `invoice_email_deliveries` ADD CONSTRAINT `invoice_deliveries_state_check` CHECK ((`status` = 'pending' AND `provider_message_id` IS NULL AND `sent_at` IS NULL AND `failed_at` IS NULL AND `failure_code` IS NULL AND `failure_message` IS NULL) OR (`status` = 'sent' AND `sent_at` IS NOT NULL AND `failed_at` IS NULL AND `failure_code` IS NULL AND `failure_message` IS NULL) OR (`status` = 'failed' AND `provider_message_id` IS NULL AND `sent_at` IS NULL AND `failed_at` IS NOT NULL AND `failure_code` IS NOT NULL AND `failure_message` IS NOT NULL))",
        );

        DB::connection($connection)->unprepared("CREATE TRIGGER `invoice_documents_issued_insert_guard` BEFORE INSERT ON `invoice_documents` FOR EACH ROW BEGIN IF (SELECT `status` FROM `invoices` WHERE `id` = NEW.`invoice_id`) <> 'issued' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice document requires issued invoice'; END IF; END");
        DB::connection($connection)->unprepared("CREATE TRIGGER `invoice_documents_immutable_update` BEFORE UPDATE ON `invoice_documents` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice document is immutable'");
        DB::connection($connection)->unprepared("CREATE TRIGGER `invoice_documents_immutable_delete` BEFORE DELETE ON `invoice_documents` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice document cannot be deleted'");
        DB::connection($connection)->unprepared("CREATE TRIGGER `invoice_deliveries_immutable_delete` BEFORE DELETE ON `invoice_email_deliveries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice delivery cannot be deleted'");
        DB::connection($connection)->unprepared(<<<'SQL'
CREATE TRIGGER `invoice_deliveries_update_guard` BEFORE UPDATE ON `invoice_email_deliveries` FOR EACH ROW
BEGIN
    IF NOT (OLD.`uuid` <=> NEW.`uuid` AND OLD.`invoice_id` <=> NEW.`invoice_id` AND OLD.`invoice_document_id` <=> NEW.`invoice_document_id` AND OLD.`recipient_email` <=> NEW.`recipient_email` AND OLD.`recipient_name` <=> NEW.`recipient_name` AND OLD.`subject` <=> NEW.`subject` AND OLD.`body_text` <=> NEW.`body_text` AND OLD.`body_html` <=> NEW.`body_html` AND OLD.`send_correlation_uuid` <=> NEW.`send_correlation_uuid` AND OLD.`attempted_at` <=> NEW.`attempted_at` AND OLD.`created_by_actor` <=> NEW.`created_by_actor` AND OLD.`created_at` <=> NEW.`created_at`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice delivery content is immutable';
    END IF;
    IF OLD.`status` <> 'pending' OR NEW.`status` NOT IN ('sent', 'failed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid invoice delivery transition';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        $hasDocuments = Schema::connection($connection)->hasTable('invoice_documents');
        $hasDeliveries = Schema::connection($connection)->hasTable('invoice_email_deliveries');
        if (($hasDocuments && DB::connection($connection)->table('invoice_documents')->exists())
            || ($hasDeliveries && DB::connection($connection)->table('invoice_email_deliveries')->exists())) {
            throw new RuntimeException('Migraci dokumentů nelze vrátit, pokud existuje historie PDF nebo odeslání.');
        }
        foreach (['invoice_deliveries_update_guard', 'invoice_deliveries_immutable_delete', 'invoice_documents_issued_insert_guard', 'invoice_documents_immutable_update', 'invoice_documents_immutable_delete'] as $trigger) {
            DB::connection($connection)->unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");
        }
        Schema::connection($connection)->dropIfExists('invoice_email_deliveries');
        Schema::connection($connection)->dropIfExists('invoice_documents');
    }
};
