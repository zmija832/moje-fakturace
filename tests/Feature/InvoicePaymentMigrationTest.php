<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business\InvoicePayment;
use App\Services\Business\InvoiceIssuer;
use App\Services\Business\InvoicePaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoicePaymentMigrationTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;
    use InteractsWithBusinessDatabases;

    protected bool $businessDatabaseTransactions = false;

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_payment_schema_is_identical_and_tenant_local(): void
    {
        $this->refreshBusinessTestDatabases();
        foreach (BusinessConnection::cases() as $connection) {
            $name = $connection->connectionName();
            $this->assertTrue(Schema::connection($name)->hasTable('invoice_payments'));
            $this->assertFalse(Schema::connection($name)->hasColumn('invoice_payments', 'business_id'));
            $this->assertSame(3, count(DB::connection($name)->select("SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND event_object_table = 'invoice_payments'")));
            $this->assertSame(1, count(DB::connection($name)->select("SELECT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'invoice_payments' AND column_name = 'correlation_uuid' AND non_unique = 0")));
        }
        $this->assertFalse(Schema::connection('central')->hasTable('invoice_payments'));
        $one = DB::connection('business_1')->select("SELECT column_name,column_type,is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'invoice_payments' ORDER BY ordinal_position");
        $two = DB::connection('business_2')->select("SELECT column_name,column_type,is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'invoice_payments' ORDER BY ordinal_position");
        $this->assertEquals($one, $two);
        $foreignKeys = DB::connection('business_1')->select("SELECT referenced_table_schema,referenced_table_name FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = 'invoice_payments' AND referenced_table_name IS NOT NULL");
        $this->assertCount(3, $foreignKeys);
        foreach ($foreignKeys as $foreignKey) {
            $this->assertSame(config('database.connections.business_1.database'), $foreignKey->referenced_table_schema);
            $this->assertContains($foreignKey->referenced_table_name, ['invoices', 'invoice_payments']);
        }
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_payment_guard_comparisons_are_safe_across_database_collations(): void
    {
        $this->refreshBusinessTestDatabases();

        foreach (BusinessConnection::cases() as $businessConnection) {
            $connection = $businessConnection->connectionName();
            $trigger = DB::connection($connection)->selectOne(
                "SELECT action_statement FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name = 'invoice_payments_insert_guard'",
            );
            $this->assertNotNull($trigger);
            $this->assertStringContainsString('BINARY invoice_status <> BINARY', $trigger->action_statement);
            $this->assertStringContainsString('BINARY invoice_currency <> BINARY NEW.currency', $trigger->action_statement);
            $this->assertStringContainsString('BINARY original_currency <> BINARY NEW.currency', $trigger->action_statement);
        }

        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $payment = app(InvoicePaymentService::class)->record($invoice->uuid, (string) Str::uuid(), [
            'amount' => '10.0000', 'currency' => 'CZK', 'paid_on' => '2026-08-14',
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertSame('10.0000', $payment->amount);
        $this->assertSame(1, InvoicePayment::query()->count());
    }

    public function test_database_and_model_reject_update_delete_duplicate_and_draft_payment(): void
    {
        $this->refreshBusinessTestDatabases();
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);
        $this->actingAs($admin);

        try {
            $invalid = new InvoicePayment;
            $invalid->forceFill([
                'invoice_id' => $draft->id, 'payment_type' => 'payment', 'amount' => '1.0000', 'currency' => 'CZK',
                'paid_on' => '2026-08-04', 'payment_method' => 'cash', 'source' => 'manual', 'correlation_uuid' => (string) Str::uuid(),
            ])->save();
            $this->fail('Databáze nesmí přijmout platbu draftu.');
        } catch (QueryException) {
            $this->assertSame(0, InvoicePayment::query()->count());
        }

        $invoice = app(InvoiceIssuer::class)->issue($draft->uuid, 1, (string) Str::uuid());
        $correlation = (string) Str::uuid();
        $payment = app(InvoicePaymentService::class)->record($invoice->uuid, $correlation, [
            'amount' => '25.0000', 'currency' => 'CZK', 'paid_on' => '2026-08-04', 'payment_method' => 'bank_transfer',
        ]);

        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::connection('business_1')->table('invoice_payments')->where('id', $payment->id);
                $operation === 'update' ? $query->update(['amount' => '30.0000']) : $query->delete();
                $this->fail('Platební ledger nesmí povolit '.$operation.'.');
            } catch (QueryException) {
                $this->assertDatabaseHas('invoice_payments', ['id' => $payment->id, 'amount' => '25.0000'], 'business_1');
            }
        }

        try {
            $duplicate = new InvoicePayment;
            $duplicate->forceFill([
                'invoice_id' => $invoice->id, 'payment_type' => 'payment', 'amount' => '1.0000', 'currency' => 'CZK',
                'paid_on' => '2026-08-04', 'payment_method' => 'cash', 'source' => 'manual', 'correlation_uuid' => $correlation,
            ])->save();
            $this->fail('Correlation UUID musí být unikátní.');
        } catch (QueryException) {
            $this->assertSame(1, InvoicePayment::query()->count());
        }

    }

    public function test_payment_migration_rolls_back_as_its_own_empty_batch(): void
    {
        $this->refreshBusinessTestDatabases();
        $connection = BusinessConnection::Business2->connectionName();
        $all = collect(glob(database_path('migrations/business/*.php')))->sort()->values();
        $target = database_path('migrations/business/2026_08_04_000000_create_invoice_payments_table.php');

        $this->assertSame(0, Artisan::call('migrate:reset', ['--database' => $connection, '--path' => [database_path('migrations/business')], '--realpath' => true, '--force' => true]));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => $connection, '--path' => $all->reject(fn (string $path): bool => basename($path) === basename($target))->all(), '--realpath' => true, '--force' => true]));
        $before = DB::connection($connection)->table('migrations')->pluck('migration')->all();
        $this->assertNotEmpty($before);
        [, $business] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($business);
        [$issuedInvoice] = $this->createIssuedInvoice();
        $this->assertSame('issued', $issuedInvoice->status->value);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => $connection, '--path' => [$target], '--realpath' => true, '--force' => true]));
        $this->assertTrue(Schema::connection($connection)->hasTable('invoice_payments'));
        $this->assertSame(0, Artisan::call('migrate:rollback', [
            '--database' => $connection, '--path' => [$target], '--realpath' => true, '--step' => 1, '--force' => true,
        ]));
        $this->assertFalse(Schema::connection($connection)->hasTable('invoice_payments'));
        $this->assertSame($before, DB::connection($connection)->table('migrations')->pluck('migration')->all());
        $this->assertFalse(DB::connection($connection)->table('migrations')->where('migration', '2026_08_04_000000_create_invoice_payments_table')->exists());
        $this->assertSame('central', DB::getDefaultConnection());
    }
}
