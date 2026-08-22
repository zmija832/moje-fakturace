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

        if (! $this->indexExists($connection, 'invoice_paid_notifications_invoice_id_index')) {
            Schema::connection($connection)->table('invoice_paid_notifications', function (Blueprint $table): void {
                $table->index('invoice_id');
            });
        }
        if ($this->indexExists($connection, 'paid_notifications_once_unique')) {
            Schema::connection($connection)->table('invoice_paid_notifications', function (Blueprint $table): void {
                $table->dropUnique('paid_notifications_once_unique');
            });
        }
        if (! $this->indexExists($connection, 'payment_notifications_delivery_unique')) {
            Schema::connection($connection)->table('invoice_paid_notifications', function (Blueprint $table): void {
                $table->unique(['triggering_payment_uuid', 'recipient_type'], 'payment_notifications_delivery_unique');
            });
        }
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        $duplicates = DB::connection($connection)->table('invoice_paid_notifications')
            ->select(['invoice_id', 'recipient_type'])
            ->groupBy(['invoice_id', 'recipient_type'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('Migraci notifikací plateb nelze vrátit, pokud již faktura eviduje více platebních událostí stejného typu.');
        }

        if ($this->indexExists($connection, 'payment_notifications_delivery_unique')) {
            Schema::connection($connection)->table('invoice_paid_notifications', function (Blueprint $table): void {
                $table->dropUnique('payment_notifications_delivery_unique');
            });
        }
        if (! $this->indexExists($connection, 'paid_notifications_once_unique')) {
            Schema::connection($connection)->table('invoice_paid_notifications', function (Blueprint $table): void {
                $table->unique(['invoice_id', 'recipient_type'], 'paid_notifications_once_unique');
            });
        }
    }

    private function indexExists(string $connection, string $index): bool
    {
        return (int) DB::connection($connection)->selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'invoice_paid_notifications'
  AND INDEX_NAME = ?
SQL, [$index])->aggregate > 0;
    }
};
