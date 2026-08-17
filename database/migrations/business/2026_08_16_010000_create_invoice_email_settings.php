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

        Schema::connection($connection)->create('invoice_email_settings', function (Blueprint $table): void {
            $table->id();
            $table->char('singleton_key', 1)->unique();
            $table->string('sender_name');
            $table->string('reply_to')->nullable();
            $table->string('subject_template');
            $table->text('body_template');
            $table->text('signature')->nullable();
            $table->boolean('attach_pdf')->default(true);
            $table->boolean('include_web_invoice')->default(true);
            $table->timestamps(6);
        });
        DB::connection($connection)->statement(
            "ALTER TABLE `invoice_email_settings` ADD CONSTRAINT `invoice_email_settings_singleton_check` CHECK (`singleton_key` = '1')",
        );
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        Schema::connection($connection)->dropIfExists('invoice_email_settings');
    }
};
