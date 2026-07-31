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
        $connection = $this->businessConnection();

        Schema::connection($connection)->create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('domestic_prefix', 10)->nullable();
            $table->string('domestic_account_number', 32)->nullable();
            $table->string('bank_code', 16)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bic', 11)->nullable();
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'currency'], 'bank_accounts_id_currency_unique');
            $table->index(
                ['archived_at', 'is_active', 'currency', 'sort_order'],
                'bank_accounts_status_currency_sort_index',
            );
        });

        DB::connection($connection)->statement(
            "ALTER TABLE `bank_accounts`
             ADD CONSTRAINT `bank_accounts_identifier_check`
             CHECK (
                (`domestic_account_number` IS NOT NULL AND `domestic_account_number` <> '')
                OR (`iban` IS NOT NULL AND `iban` <> '')
             )",
        );

        Schema::connection($connection)->create('bank_account_defaults', function (Blueprint $table): void {
            $table->char('currency', 3)->primary();
            $table->unsignedBigInteger('bank_account_id')->unique();
            $table->timestamps();

            $table->foreign(['bank_account_id', 'currency'], 'bank_account_defaults_account_currency_foreign')
                ->references(['id', 'currency'])
                ->on('bank_accounts')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $connection = $this->businessConnection();

        Schema::connection($connection)->dropIfExists('bank_account_defaults');
        Schema::connection($connection)->dropIfExists('bank_accounts');
    }

    private function businessConnection(): string
    {
        return BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
    }
};
