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

        Schema::connection($connection)->create('invoice_labels', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 80)->unique();
            $table->string('color', 16);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'name'], 'invoice_labels_active_name_index');
        });

        Schema::connection($connection)->create('invoice_invoice_label', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('invoice_label_id');
            $table->timestamps();
            $table->primary(['invoice_id', 'invoice_label_id']);
            $table->index(['invoice_label_id', 'invoice_id'], 'invoice_label_invoice_index');
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('invoice_label_id')->references('id')->on('invoice_labels')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        Schema::connection($connection)->dropIfExists('invoice_invoice_label');
        Schema::connection($connection)->dropIfExists('invoice_labels');
    }
};
