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

        Schema::connection($connection)->create('company_settings', function (Blueprint $table): void {
            $table->id();
            $table->char('singleton_key', 1)->default('1')->unique();
            $table->string('legal_name');
            $table->string('additional_name')->nullable();
            $table->string('registration_number', 16);
            $table->string('tax_id', 32)->nullable();
            $table->string('vat_id', 32)->nullable();
            $table->string('street');
            $table->string('house_number', 32)->nullable();
            $table->string('orientation_number', 32)->nullable();
            $table->string('city', 128);
            $table->string('postal_code', 20);
            $table->char('country_code', 2)->default('CZ');
            $table->string('email');
            $table->string('phone', 64)->nullable();
            $table->string('website')->nullable();
            $table->char('default_currency', 3)->default('CZK');
            $table->string('document_locale', 10)->default('cs');
            $table->string('timezone', 64)->default('Europe/Prague');
            $table->boolean('is_vat_payer')->default(false);
            $table->date('vat_registered_on')->nullable();
            $table->unsignedSmallInteger('default_due_days')->default(14);
            $table->string('default_payment_method', 32)->default('bank_transfer');
            $table->text('invoice_intro')->nullable();
            $table->text('invoice_outro')->nullable();
            $table->timestamps();
        });

        DB::connection($connection)->statement(
            "ALTER TABLE `company_settings`
             ADD CONSTRAINT `company_settings_singleton_key_check`
             CHECK (`singleton_key` = '1')",
        );
    }

    public function down(): void
    {
        Schema::connection($this->businessConnection())->dropIfExists('company_settings');
    }

    private function businessConnection(): string
    {
        return BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
    }
};
