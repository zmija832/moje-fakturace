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

        Schema::connection($connection)->create('vat_rates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('code', 32)->index();
            $table->string('tax_type', 32)->index();
            $table->decimal('percentage', 7, 4)->nullable();
            $table->date('valid_from')->index();
            $table->date('valid_to')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->index(
                ['code', 'archived_at', 'valid_from', 'valid_to'],
                'vat_rates_code_validity_index',
            );
            $table->index(
                ['archived_at', 'is_active', 'valid_from', 'valid_to'],
                'vat_rates_resolve_index',
            );
        });

        DB::connection($connection)->statement(
            "ALTER TABLE `vat_rates`
             ADD CONSTRAINT `vat_rates_values_check`
             CHECK (
                `tax_type` IN ('standard', 'reduced', 'zero', 'exempt', 'reverse_charge', 'out_of_scope')
                AND (`valid_to` IS NULL OR `valid_to` >= `valid_from`)
                AND (
                    (`tax_type` IN ('standard', 'reduced') AND `percentage` IS NOT NULL AND `percentage` BETWEEN 0 AND 100)
                    OR (`tax_type` = 'zero' AND `percentage` = 0)
                    OR (`tax_type` IN ('exempt', 'reverse_charge', 'out_of_scope') AND `percentage` IS NULL)
                )
             )",
        );

        Schema::connection($connection)->create('vat_rate_defaults', function (Blueprint $table): void {
            $table->string('context', 32)->primary();
            $table->unsignedBigInteger('vat_rate_id')->unique();
            $table->timestamps();

            $table->foreign('vat_rate_id')
                ->references('id')
                ->on('vat_rates')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });

        DB::connection($connection)->statement(
            "ALTER TABLE `vat_rate_defaults`
             ADD CONSTRAINT `vat_rate_defaults_context_check`
             CHECK (`context` IN ('sales'))",
        );
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        Schema::connection($connection)->dropIfExists('vat_rate_defaults');
        Schema::connection($connection)->dropIfExists('vat_rates');
    }
};
