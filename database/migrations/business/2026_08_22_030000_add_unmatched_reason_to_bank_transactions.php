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

        Schema::connection($connection)->table('bank_transactions', function (Blueprint $table): void {
            $table->string('unmatched_reason', 64)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();

        Schema::connection($connection)->table('bank_transactions', function (Blueprint $table): void {
            $table->dropColumn('unmatched_reason');
        });
    }
};
