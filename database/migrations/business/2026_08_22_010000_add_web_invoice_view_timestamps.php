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

        Schema::connection($connection)->table('invoice_public_links', function (Blueprint $table): void {
            $table->timestamp('first_viewed_at', 6)->nullable()->after('created_by_actor');
            $table->timestamp('last_viewed_at', 6)->nullable()->after('first_viewed_at');
        });
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        if (Schema::connection($connection)->hasTable('invoice_public_links')
            && DB::connection($connection)->table('invoice_public_links')->whereNotNull('first_viewed_at')->exists()) {
            throw new RuntimeException('Migraci zobrazení Webfaktury nelze vrátit, pokud již bylo zobrazení zaznamenáno.');
        }

        Schema::connection($connection)->table('invoice_public_links', function (Blueprint $table): void {
            $table->dropColumn(['first_viewed_at', 'last_viewed_at']);
        });
    }
};
