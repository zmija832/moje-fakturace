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

        Schema::connection($connection)->create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('event', 64)->index();
            $table->string('actor_user_uuid', 64)->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('auditable_type', 64);
            $table->uuid('auditable_uuid')->nullable();
            $table->string('subject_type', 64)->nullable();
            $table->uuid('subject_uuid')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('changed_fields')->nullable();
            $table->json('metadata')->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('occurred_at', 6)->index();
            $table->timestamps(6);

            $table->index(['auditable_type', 'auditable_uuid'], 'audit_logs_auditable_index');
            $table->index(['subject_type', 'subject_uuid'], 'audit_logs_subject_index');
        });
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        Schema::connection($connection)->dropIfExists('audit_logs');
    }
};
