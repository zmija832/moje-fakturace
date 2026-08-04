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

        Schema::connection($connection)->create('invoice_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->string('payment_type', 16);
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3);
            $table->date('paid_on');
            $table->timestamp('received_at', 6)->nullable();
            $table->string('payment_method', 32);
            $table->string('reference')->nullable();
            $table->string('variable_symbol', 20)->nullable();
            $table->text('note')->nullable();
            $table->string('source', 32);
            $table->string('external_id')->nullable();
            $table->uuid('correlation_uuid')->unique();
            $table->unsignedBigInteger('reverses_payment_id')->nullable();
            $table->string('created_by_actor')->nullable();
            $table->timestamps(6);
            $table->unique(['id', 'invoice_id'], 'invoice_payments_id_invoice_unique');
            $table->unique(['source', 'external_id'], 'invoice_payments_source_external_unique');
            $table->index(['invoice_id', 'paid_on', 'id'], 'invoice_payments_invoice_history_index');
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign(['reverses_payment_id', 'invoice_id'], 'invoice_payments_reversal_invoice_foreign')
                ->references(['id', 'invoice_id'])->on('invoice_payments')->restrictOnUpdate()->restrictOnDelete();
        });
        DB::connection($connection)->statement(
            "ALTER TABLE `invoice_payments` ADD CONSTRAINT `invoice_payments_values_check` CHECK (`amount` > 0 AND `payment_type` IN ('payment', 'reversal') AND `source` IN ('manual', 'future_bank_import') AND `payment_method` IN ('bank_transfer', 'cash', 'card', 'cod') AND ((`payment_type` = 'payment' AND `reverses_payment_id` IS NULL) OR (`payment_type` = 'reversal' AND `reverses_payment_id` IS NOT NULL)) AND (`source` = 'future_bank_import' OR `external_id` IS NULL))",
        );

        DB::connection($connection)->unprepared(<<<'SQL'
CREATE TRIGGER `invoice_payments_insert_guard` BEFORE INSERT ON `invoice_payments` FOR EACH ROW
BEGIN
    DECLARE invoice_status VARCHAR(16);
    DECLARE invoice_currency CHAR(3);
    DECLARE original_type VARCHAR(16);
    DECLARE original_currency CHAR(3);
    DECLARE original_amount DECIMAL(19,4);
    DECLARE reversed_amount DECIMAL(19,4);
    DECLARE ledger_total DECIMAL(19,4);

    SELECT `status`, `currency` INTO invoice_status, invoice_currency FROM `invoices` WHERE `id` = NEW.`invoice_id`;
    IF invoice_status IS NULL OR invoice_status <> 'issued' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice payment requires issued invoice';
    END IF;
    IF invoice_currency <> NEW.`currency` THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice payment currency mismatch';
    END IF;

    SELECT COALESCE(SUM(CASE WHEN `payment_type` = 'payment' THEN `amount` ELSE -`amount` END), 0)
        INTO ledger_total FROM `invoice_payments` WHERE `invoice_id` = NEW.`invoice_id`;

    IF NEW.`payment_type` = 'reversal' THEN
        SELECT `payment_type`, `currency`, `amount` INTO original_type, original_currency, original_amount
            FROM `invoice_payments` WHERE `id` = NEW.`reverses_payment_id` AND `invoice_id` = NEW.`invoice_id`;
        IF original_type IS NULL OR original_type <> 'payment' OR original_currency <> NEW.`currency` THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice reversal must reference original payment';
        END IF;
        SELECT COALESCE(SUM(`amount`), 0) INTO reversed_amount
            FROM `invoice_payments` WHERE `reverses_payment_id` = NEW.`reverses_payment_id`;
        IF reversed_amount + NEW.`amount` > original_amount OR ledger_total - NEW.`amount` < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice reversal exceeds paid amount';
        END IF;
    END IF;
END
SQL);
        DB::connection($connection)->unprepared("CREATE TRIGGER `invoice_payments_immutable_update` BEFORE UPDATE ON `invoice_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice payment is immutable'");
        DB::connection($connection)->unprepared("CREATE TRIGGER `invoice_payments_immutable_delete` BEFORE DELETE ON `invoice_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice payment cannot be deleted'");
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        if (Schema::connection($connection)->hasTable('invoice_payments')
            && DB::connection($connection)->table('invoice_payments')->exists()) {
            throw new RuntimeException('Migraci plateb nelze vrátit, pokud existuje platební historie.');
        }
        foreach (['invoice_payments_insert_guard', 'invoice_payments_immutable_update', 'invoice_payments_immutable_delete'] as $trigger) {
            DB::connection($connection)->unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");
        }
        Schema::connection($connection)->dropIfExists('invoice_payments');
    }
};
