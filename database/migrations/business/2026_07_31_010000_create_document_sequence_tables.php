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
        $connection = $this->businessConnection();

        Schema::connection($connection)->create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('document_type', 32);
            $table->string('name');
            $table->string('prefix', 64)->default('');
            $table->string('suffix', 64)->default('');
            $table->string('year_format', 8);
            $table->unsignedTinyInteger('sequence_digits');
            $table->unsignedBigInteger('start_number');
            $table->unsignedBigInteger('next_number');
            $table->string('reset_period', 16);
            $table->char('current_period', 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'document_type'], 'document_sequences_id_type_unique');
            $table->unique(
                ['document_type', 'prefix', 'suffix', 'year_format', 'sequence_digits', 'reset_period'],
                'document_sequences_format_unique',
            );
            $table->index(
                ['archived_at', 'is_active', 'document_type', 'sort_order'],
                'document_sequences_status_type_sort_index',
            );
        });

        DB::connection($connection)->statement(
            "ALTER TABLE `document_sequences`
             ADD CONSTRAINT `document_sequences_values_check`
             CHECK (
                `document_type` IN ('issued_invoice', 'advance_invoice', 'credit_note', 'cash_receipt')
                AND `year_format` IN ('none', 'yy', 'yyyy')
                AND `reset_period` IN ('never', 'yearly')
                AND `sequence_digits` BETWEEN 1 AND 12
                AND `start_number` BETWEEN 1 AND 999999999999
                AND `next_number` BETWEEN 1 AND 1000000000000
                AND (
                    (`reset_period` = 'never' AND `current_period` IS NULL)
                    OR (`reset_period` = 'yearly' AND (`current_period` IS NULL OR `current_period` REGEXP '^[0-9]{4}$'))
                )
             )",
        );

        Schema::connection($connection)->create('document_sequence_defaults', function (Blueprint $table): void {
            $table->string('document_type', 32)->primary();
            $table->unsignedBigInteger('document_sequence_id')->unique();
            $table->timestamps();

            $table->foreign(
                ['document_sequence_id', 'document_type'],
                'document_sequence_defaults_sequence_type_foreign',
            )
                ->references(['id', 'document_type'])
                ->on('document_sequences')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });

        Schema::connection($connection)->create('document_number_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('correlation_uuid')->unique();
            $table->unsignedBigInteger('document_sequence_id');
            $table->string('document_type', 32);
            $table->string('period', 16);
            $table->unsignedBigInteger('sequence_number');
            $table->string('formatted_number');
            $table->timestamp('allocated_at');
            $table->uuid('document_uuid')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['document_sequence_id', 'period', 'sequence_number'],
                'document_allocations_sequence_period_number_unique',
            );
            $table->unique(
                ['document_type', 'formatted_number'],
                'document_allocations_type_formatted_unique',
            );
            $table->foreign(
                ['document_sequence_id', 'document_type'],
                'document_allocations_sequence_type_foreign',
            )
                ->references(['id', 'document_type'])
                ->on('document_sequences')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $connection = $this->businessConnection();

        Schema::connection($connection)->dropIfExists('document_number_allocations');
        Schema::connection($connection)->dropIfExists('document_sequence_defaults');
        Schema::connection($connection)->dropIfExists('document_sequences');
    }

    private function businessConnection(): string
    {
        return BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
    }
};
