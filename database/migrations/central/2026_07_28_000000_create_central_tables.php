<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('display_name');
            $table->string('registration_number', 16);
            $table->string('short_label', 32);
            $table->string('visual_identifier', 32)->nullable();
            $table->string('connection_name', 32);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::connection('central')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->foreignId('last_business_id')
                ->nullable()
                ->constrained('businesses')
                ->nullOnDelete();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::connection('central')->create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection('central')->create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::connection('central')->create('user_business_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('admin');
            $table->timestamps();
            $table->unique(['user_id', 'business_id']);
        });

        Schema::connection('central')->create('login_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 16);
            $table->char('attempted_email_hash', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['event', 'occurred_at']);
        });

        Schema::connection('central')->create('business_switch_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('to_business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->uuid('requested_business_uuid');
            $table->string('result', 16);
            $table->string('reason', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['user_id', 'occurred_at']);
        });

        Schema::connection('central')->create('application_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('value_type', 16)->default('string');
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('application_settings');
        Schema::connection('central')->dropIfExists('business_switch_audits');
        Schema::connection('central')->dropIfExists('login_audits');
        Schema::connection('central')->dropIfExists('user_business_access');
        Schema::connection('central')->dropIfExists('sessions');
        Schema::connection('central')->dropIfExists('password_reset_tokens');
        Schema::connection('central')->dropIfExists('users');
        Schema::connection('central')->dropIfExists('businesses');
    }
};
