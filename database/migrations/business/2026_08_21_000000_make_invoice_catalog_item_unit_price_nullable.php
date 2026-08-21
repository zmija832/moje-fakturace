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

        Schema::connection($connection)->table('invoice_catalog_items', function (Blueprint $table): void {
            $table->decimal('unit_price', 19, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();

        Schema::connection($connection)->table('invoice_catalog_items', function (Blueprint $table): void {
            $table->decimal('unit_price', 19, 4)->nullable(false)->change();
        });
    }
};
