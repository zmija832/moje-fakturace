<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoicePaymentNotificationMigrationTest extends TestCase
{
    public function test_notification_idempotence_is_scoped_per_payment_on_both_tenants(): void
    {
        foreach (['business_1', 'business_2'] as $connection) {
            $columns = collect(DB::connection($connection)->select(<<<'SQL'
SELECT COLUMN_NAME AS name
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'invoice_paid_notifications'
  AND INDEX_NAME = 'payment_notifications_delivery_unique'
ORDER BY SEQ_IN_INDEX
SQL))->pluck('name')->all();

            $this->assertSame(['triggering_payment_uuid', 'recipient_type'], $columns);
            $this->assertSame(0, (int) DB::connection($connection)->selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'invoice_paid_notifications'
  AND INDEX_NAME = 'paid_notifications_once_unique'
SQL)->aggregate);
            $this->assertTrue(DB::connection($connection)->table('migrations')
                ->where('migration', '2026_08_22_020000_scope_payment_notifications_per_payment')->exists());
        }

        $this->assertFalse(Schema::connection('central')->hasTable('invoice_paid_notifications'));
    }
}
