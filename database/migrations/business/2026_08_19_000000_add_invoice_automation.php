<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoice_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 160);
            $table->uuid('client_uuid');
            $table->uuid('bank_account_uuid')->nullable();
            $table->char('currency', 3);
            $table->string('payment_method', 32);
            $table->unsignedSmallInteger('due_days')->default(14);
            $table->unsignedTinyInteger('interval_months');
            $table->unsignedTinyInteger('anchor_day');
            $table->date('next_run_on');
            $table->timestamp('last_run_at')->nullable();
            $table->string('mode', 24);
            $table->boolean('auto_send')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->string('invoice_discount_type', 24)->default('none');
            $table->decimal('invoice_discount_value', 19, 4)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['is_active', 'next_run_on'], 'recurring_templates_due_index');
        });
        Schema::create('recurring_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recurring_invoice_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description', 1000);
            $table->decimal('quantity', 19, 6);
            $table->string('unit', 32);
            $table->decimal('unit_price', 19, 4);
            $table->string('discount_type', 24)->default('none');
            $table->decimal('discount_value', 19, 4)->nullable();
            $table->uuid('vat_rate_uuid')->nullable();
            $table->timestamps();
            $table->unique(['recurring_invoice_template_id', 'position'], 'recurring_items_position_unique');
        });
        Schema::create('recurring_invoice_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('recurring_invoice_template_id')->constrained()->restrictOnDelete();
            $table->date('scheduled_on');
            $table->string('status', 24);
            $table->uuid('invoice_uuid')->nullable();
            $table->uuid('correlation_uuid')->unique();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamps();
            $table->unique(['recurring_invoice_template_id', 'scheduled_on'], 'recurring_runs_period_unique');
            $table->index(['status', 'started_at']);
        });
        Schema::create('invoice_automation_settings', function (Blueprint $table): void {
            $table->string('singleton_key', 1)->primary();
            $table->boolean('reminders_enabled')->default(false);
            $table->string('reminder_mode', 16)->default('prepare');
            $table->unsignedSmallInteger('reminder_day_1')->nullable()->default(1);
            $table->unsignedSmallInteger('reminder_day_2')->nullable()->default(7);
            $table->unsignedSmallInteger('reminder_day_3')->nullable()->default(14);
            for ($level = 1; $level <= 3; $level++) {
                $table->string("reminder_subject_{$level}", 255);
                $table->text("reminder_body_{$level}");
            }
            $table->boolean('notify_admin_when_paid')->default(true);
            $table->boolean('notify_customer_when_paid')->default(false);
            $table->string('paid_subject', 255);
            $table->text('paid_body');
            $table->timestamps();
        });
        Schema::create('invoice_reminder_overrides', function (Blueprint $table): void {
            $table->foreignId('invoice_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('disabled')->default(false);
            $table->string('updated_by_actor', 160)->nullable();
            $table->timestamps();
        });
        Schema::create('invoice_reminders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->date('scheduled_on');
            $table->string('status', 16);
            $table->string('recipient_email', 255)->nullable();
            $table->string('subject', 255);
            $table->text('body_text');
            $table->uuid('correlation_uuid')->unique();
            $table->uuid('claim_token')->nullable()->unique();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedSmallInteger('send_attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'level'], 'invoice_reminders_level_unique');
            $table->index(['status', 'scheduled_on']);
        });
        Schema::create('invoice_paid_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->uuid('triggering_payment_uuid');
            $table->string('recipient_type', 16);
            $table->string('recipient_email', 255)->nullable();
            $table->string('subject', 255);
            $table->text('body_text');
            $table->string('status', 16);
            $table->uuid('correlation_uuid')->unique();
            $table->uuid('claim_token')->nullable()->unique();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedSmallInteger('send_attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'recipient_type'], 'paid_notifications_once_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_paid_notifications');
        Schema::dropIfExists('invoice_reminders');
        Schema::dropIfExists('invoice_reminder_overrides');
        Schema::dropIfExists('invoice_automation_settings');
        Schema::dropIfExists('recurring_invoice_runs');
        Schema::dropIfExists('recurring_invoice_items');
        Schema::dropIfExists('recurring_invoice_templates');
    }
};
