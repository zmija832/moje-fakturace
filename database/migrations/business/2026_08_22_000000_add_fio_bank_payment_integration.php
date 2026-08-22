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

        Schema::connection($connection)->create('fio_bank_account_settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('bank_account_id')->unique();
            $table->text('encrypted_token')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->uuid('sync_claim_token')->nullable()->unique();
            $table->timestamp('sync_claimed_at', 6)->nullable();
            $table->timestamp('last_attempt_at', 6)->nullable();
            $table->timestamp('last_successful_sync_at', 6)->nullable();
            $table->timestamp('last_error_at', 6)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps(6);
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->restrictOnUpdate()->cascadeOnDelete();
            $table->index(['is_enabled', 'sync_claimed_at'], 'fio_settings_enabled_claim_index');
        });

        Schema::connection($connection)->create('bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('bank_account_id');
            $table->string('source', 16)->default('fio');
            $table->string('external_transaction_id', 128);
            $table->date('booked_on');
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3);
            $table->string('variable_symbol', 20)->nullable();
            $table->string('counterparty_account', 64)->nullable();
            $table->string('counterparty_bank_code', 16)->nullable();
            $table->string('counterparty_name')->nullable();
            $table->text('message')->nullable();
            $table->string('transaction_type', 128)->nullable();
            $table->string('status', 16)->default('unmatched');
            $table->unsignedBigInteger('matched_invoice_id')->nullable();
            $table->unsignedBigInteger('invoice_payment_id')->nullable()->unique();
            $table->timestamp('imported_at', 6);
            $table->timestamp('matched_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['bank_account_id', 'source', 'external_transaction_id'], 'bank_transactions_external_unique');
            $table->index(['status', 'booked_on'], 'bank_transactions_status_date_index');
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('matched_invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('invoice_payment_id')->references('id')->on('invoice_payments')->restrictOnUpdate()->restrictOnDelete();
        });

        DB::connection($connection)->statement(
            "ALTER TABLE `bank_transactions` ADD CONSTRAINT `bank_transactions_values_check` CHECK (`amount` > 0 AND `source` = 'fio' AND `status` IN ('unmatched', 'matched', 'ignored') AND ((`status` = 'matched' AND `matched_invoice_id` IS NOT NULL AND `invoice_payment_id` IS NOT NULL AND `matched_at` IS NOT NULL) OR (`status` <> 'matched' AND `matched_invoice_id` IS NULL AND `invoice_payment_id` IS NULL AND `matched_at` IS NULL)))",
        );
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        if ((Schema::connection($connection)->hasTable('bank_transactions')
                && DB::connection($connection)->table('bank_transactions')->exists())
            || (Schema::connection($connection)->hasTable('fio_bank_account_settings')
                && DB::connection($connection)->table('fio_bank_account_settings')->exists())) {
            throw new RuntimeException('Migraci Fio integrace nelze vrátit, pokud obsahuje nastavení nebo importované bankovní platby.');
        }
        Schema::connection($connection)->dropIfExists('bank_transactions');
        Schema::connection($connection)->dropIfExists('fio_bank_account_settings');
    }
};
