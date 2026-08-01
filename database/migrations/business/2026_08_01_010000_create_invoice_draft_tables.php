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

        Schema::connection($connection)->create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('document_type', 32);
            $table->string('status', 32)->index();
            $table->char('currency', 3)->index();
            $table->date('issued_on')->index();
            $table->date('taxable_supply_on')->index();
            $table->date('due_on')->index();
            $table->string('payment_method', 32);
            $table->string('variable_symbol', 20)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        DB::connection($connection)->statement(
            "ALTER TABLE `invoices` ADD CONSTRAINT `invoices_part_one_values_check`
             CHECK (`document_type` = 'issued_invoice' AND `status` = 'draft' AND `due_on` >= `issued_on`)",
        );

        Schema::connection($connection)->create('invoice_supplier_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_id')->primary();
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
            $table->char('country_code', 2);
            $table->string('email');
            $table->string('phone', 64)->nullable();
            $table->string('website')->nullable();
            $table->boolean('is_vat_payer');
            $table->date('vat_registered_on')->nullable();
            $table->text('invoice_intro')->nullable();
            $table->text('invoice_outro')->nullable();
            $table->timestamps();
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
        });

        Schema::connection($connection)->create('invoice_customer_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_id')->primary();
            $table->uuid('source_client_uuid');
            $table->string('client_type', 16);
            $table->string('display_name');
            $table->string('company_name')->nullable();
            $table->string('first_name', 128)->nullable();
            $table->string('last_name', 128)->nullable();
            $table->string('registration_number', 32)->nullable();
            $table->string('tax_id', 32)->nullable();
            $table->string('vat_id', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('website')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('street');
            $table->string('house_number', 32)->nullable();
            $table->string('orientation_number', 32)->nullable();
            $table->string('city', 128);
            $table->string('postal_code', 16);
            $table->char('country_code', 2);
            $table->string('delivery_name')->nullable();
            $table->string('delivery_street')->nullable();
            $table->string('delivery_house_number', 32)->nullable();
            $table->string('delivery_orientation_number', 32)->nullable();
            $table->string('delivery_city', 128)->nullable();
            $table->string('delivery_postal_code', 16)->nullable();
            $table->char('delivery_country_code', 2)->nullable();
            $table->string('language', 10)->nullable();
            $table->timestamps();
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
        });

        Schema::connection($connection)->create('invoice_bank_account_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_id')->primary();
            $table->uuid('source_bank_account_uuid');
            $table->string('name');
            $table->string('domestic_prefix', 10)->nullable();
            $table->string('domestic_account_number', 32)->nullable();
            $table->string('bank_code', 16)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bic', 11)->nullable();
            $table->char('currency', 3);
            $table->timestamps();
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
        });

        Schema::connection($connection)->create('invoice_vat_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->uuid('source_vat_rate_uuid');
            $table->string('name');
            $table->string('code', 32);
            $table->string('tax_type', 32);
            $table->decimal('percentage', 7, 4)->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'source_vat_rate_uuid'], 'invoice_vat_snapshots_source_unique');
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
        });

        Schema::connection($connection)->create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('invoice_vat_snapshot_id');
            $table->unsignedSmallInteger('position');
            $table->string('description');
            $table->decimal('quantity', 18, 4);
            $table->string('unit', 32)->nullable();
            $table->decimal('unit_price', 19, 4);
            $table->timestamps();
            $table->unique(['invoice_id', 'position'], 'invoice_items_position_unique');
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('invoice_vat_snapshot_id')->references('id')->on('invoice_vat_snapshots')->restrictOnUpdate()->restrictOnDelete();
        });

        foreach (['invoice_supplier_snapshots', 'invoice_customer_snapshots', 'invoice_bank_account_snapshots', 'invoice_vat_snapshots'] as $table) {
            DB::connection($connection)->unprepared(
                "CREATE TRIGGER `{$table}_immutable_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice snapshot is immutable'",
            );
            DB::connection($connection)->unprepared(
                "CREATE TRIGGER `{$table}_immutable_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice snapshot is immutable'",
            );
        }
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        Schema::connection($connection)->dropIfExists('invoice_items');
        Schema::connection($connection)->dropIfExists('invoice_vat_snapshots');
        Schema::connection($connection)->dropIfExists('invoice_bank_account_snapshots');
        Schema::connection($connection)->dropIfExists('invoice_customer_snapshots');
        Schema::connection($connection)->dropIfExists('invoice_supplier_snapshots');
        Schema::connection($connection)->dropIfExists('invoices');
    }
};
