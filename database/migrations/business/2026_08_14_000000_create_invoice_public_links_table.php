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

        Schema::connection($connection)->create('invoice_public_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->char('token_hash', 64)->unique();
            $table->text('token_ciphertext');
            $table->string('created_by_actor')->nullable();
            $table->timestamp('revoked_at', 6)->nullable();
            $table->string('revoked_by_actor')->nullable();
            $table->timestamps(6);

            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
            $table->index(['invoice_id', 'revoked_at'], 'invoice_public_links_active_index');
        });
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        if (Schema::connection($connection)->hasTable('invoice_public_links')
            && DB::connection($connection)->table('invoice_public_links')->exists()) {
            throw new RuntimeException('Migraci Webfaktury nelze vrátit, pokud existují veřejné odkazy.');
        }

        Schema::connection($connection)->dropIfExists('invoice_public_links');
    }
};
