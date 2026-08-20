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

        Schema::connection($connection)->create('invoice_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->decimal('unit_price', 19, 4);
            $table->string('unit', 32);
            $table->char('currency', 3);
            $table->uuid('vat_rate_uuid')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['currency', 'is_active', 'name'], 'invoice_catalog_items_search_index');
            $table->foreign('vat_rate_uuid')->references('uuid')->on('vat_rates')->restrictOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        Schema::connection($connection)->dropIfExists('invoice_catalog_items');
    }
};
