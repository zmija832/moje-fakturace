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
        if (! Schema::connection($connection)->hasColumn('invoice_email_settings', 'pdf_style')) {
            Schema::connection($connection)->table('invoice_email_settings', function (Blueprint $table): void {
                $table->string('pdf_style', 24)->default('classic')->after('template_style');
            });
        }
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        if (Schema::connection($connection)->hasColumn('invoice_email_settings', 'pdf_style')) {
            Schema::connection($connection)->table('invoice_email_settings', fn (Blueprint $table) => $table->dropColumn('pdf_style'));
        }
    }
};
