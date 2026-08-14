<?php

namespace Tests;

use App\Enums\BusinessConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\EnsuresSafeTestDatabases;

abstract class TestCase extends BaseTestCase
{
    use EnsuresSafeTestDatabases;
    use RefreshDatabase {
        refreshDatabase as protected refreshDatabaseWithoutSafetyChecks;
    }

    protected $connectionsToTransact = ['central', 'business_1', 'business_2'];

    protected bool $businessDatabaseTransactions = true;

    /** @var list<string> */
    protected array $businessDatabaseTransactionExclusions = [];

    private static bool $businessTestDatabasesRequireRefresh = false;

    private static bool $businessTestDatabasesPrepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function refreshDatabase(): void
    {
        $this->ensureSafeTestDatabases();
        $this->refreshDatabaseWithoutSafetyChecks();
    }

    protected function beforeRefreshingDatabase(): void
    {
        if ($this->usesBusinessDatabaseTransactions()) {
            $this->ensureBusinessTestDatabasesMigrated();
        }
    }

    protected function connectionsToTransact(): array
    {
        return $this->usesBusinessDatabaseTransactions()
            ? $this->connectionsToTransact
            : ['central'];
    }

    protected function usesBusinessDatabaseTransactions(): bool
    {
        return $this->businessDatabaseTransactions
            && ! in_array($this->name(), $this->businessDatabaseTransactionExclusions, true);
    }

    protected function ensureBusinessTestDatabasesMigrated(): void
    {
        $this->ensureSafeTestDatabases();

        if (! self::$businessTestDatabasesPrepared || self::$businessTestDatabasesRequireRefresh) {
            foreach (BusinessConnection::cases() as $connection) {
                $this->migrateFreshBusinessTestDatabase($connection->connectionName());
            }

            self::$businessTestDatabasesPrepared = true;
            self::$businessTestDatabasesRequireRefresh = false;

            return;
        }

        foreach (BusinessConnection::cases() as $connection) {
            $name = $connection->connectionName();

            if (! $this->businessTestSchemaIsCurrent($name)) {
                $this->migrateFreshBusinessTestDatabase($name);
            }
        }

        self::$businessTestDatabasesPrepared = true;
    }

    protected function markBusinessTestDatabasesDirty(): void
    {
        self::$businessTestDatabasesRequireRefresh = true;
    }

    protected function migrateFreshBusinessTestDatabases(): void
    {
        $this->ensureSafeTestDatabases();

        foreach (BusinessConnection::cases() as $connection) {
            $this->migrateFreshBusinessTestDatabase($connection->connectionName());
        }
    }

    private function migrateFreshBusinessTestDatabase(string $connection): void
    {
        $defaultConnection = DB::getDefaultConnection();
        $exitCode = Artisan::call('migrate:fresh', [
            '--database' => $connection,
            '--path' => [database_path('migrations/business')],
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException("Obnova testovací databáze {$connection} selhala.");
        }

        if (DB::getDefaultConnection() !== $defaultConnection) {
            throw new RuntimeException('Obnova business testovacích databází změnila default connection.');
        }
    }

    private function businessTestSchemaIsCurrent(string $connection): bool
    {
        if (! Schema::connection($connection)->hasTable('migrations')) {
            return false;
        }

        $requiredTables = [
            'company_settings', 'bank_accounts', 'clients', 'document_sequences',
            'audit_logs', 'vat_rates', 'invoices', 'invoice_revisions',
            'invoice_documents', 'invoice_email_deliveries', 'invoice_payments',
            'invoice_public_links',
        ];
        $existingTables = collect(Schema::connection($connection)->getTables())
            ->pluck('name')
            ->all();

        if (array_diff($requiredTables, $existingTables) !== []) {
            return false;
        }

        return DB::connection($connection)
            ->table('migrations')
            ->where('migration', '2026_08_15_010000_add_invoice_archival')
            ->exists();
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--drop-views' => false,
            '--drop-types' => false,
            '--seed' => false,
        ];
    }
}
