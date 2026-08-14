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

        Schema::connection($connection)->table('invoices', function (Blueprint $table): void {
            $table->timestamp('archived_at', 6)->nullable()->after('issued_at');
            $table->index(['archived_at', 'status'], 'invoices_archived_status_index');
        });
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        if (Schema::connection($connection)->hasColumn('invoices', 'archived_at')
            && DB::connection($connection)->table('invoices')->whereNotNull('archived_at')->exists()) {
            throw new RuntimeException('Archivaci faktur nelze vrátit, dokud existují archivované koncepty.');
        }

        Schema::connection($connection)->table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_archived_status_index');
            $table->dropColumn('archived_at');
        });
    }
};
