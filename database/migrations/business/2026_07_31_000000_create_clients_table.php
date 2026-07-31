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

        Schema::connection($connection)->create('clients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 16);
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
            $table->char('country_code', 2)->default('CZ');
            $table->string('delivery_name')->nullable();
            $table->string('delivery_street')->nullable();
            $table->string('delivery_house_number', 32)->nullable();
            $table->string('delivery_orientation_number', 32)->nullable();
            $table->string('delivery_city', 128)->nullable();
            $table->string('delivery_postal_code', 16)->nullable();
            $table->char('delivery_country_code', 2)->nullable();
            $table->char('default_currency', 3)->nullable();
            $table->unsignedSmallInteger('default_due_days')->nullable();
            $table->string('default_payment_method', 32)->nullable();
            $table->string('language', 10)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(
                ['archived_at', 'is_active', 'type', 'display_name'],
                'clients_status_type_name_index',
            );
            $table->index('registration_number');
            $table->index('email');
        });

        DB::connection($connection)->statement(
            "ALTER TABLE `clients`
             ADD CONSTRAINT `clients_type_check`
             CHECK (`type` IN ('company', 'person'))",
        );
    }

    public function down(): void
    {
        Schema::connection($this->businessConnection())->dropIfExists('clients');
    }

    private function businessConnection(): string
    {
        return BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
    }
};
