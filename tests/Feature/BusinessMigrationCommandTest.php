<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class BusinessMigrationCommandTest extends TestCase
{
    use InteractsWithBusinessDatabases;

    private const PART_ONE_MIGRATIONS = [
        '2026_07_30_000000_create_company_settings_table',
        '2026_07_30_010000_create_bank_accounts_tables',
        '2026_07_31_000000_create_clients_table',
        '2026_07_31_010000_create_document_sequence_tables',
        '2026_07_31_020000_create_audit_logs_table',
        '2026_08_01_000000_create_vat_rate_tables',
        '2026_08_01_010000_create_invoice_draft_tables',
    ];

    private const REVISION_MIGRATION = '2026_08_01_020000_add_invoice_draft_revisions';

    private const ISSUANCE_MIGRATION = '2026_08_02_000000_add_invoice_issuance_workflow';

    private const DELIVERY_MIGRATION = '2026_08_03_000000_add_invoice_documents_and_deliveries';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBusinessTestMigrations();
    }

    protected function tearDown(): void
    {
        try {
            $this->refreshBusinessTestDatabases();
        } finally {
            parent::tearDown();
        }
    }

    public function test_command_migrates_both_business_databases_but_not_central(): void
    {
        $exitCode = Artisan::call('app:migrate-businesses');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('business_1', $output);
        $this->assertStringContainsString('business_2', $output);
        $this->assertTrue(Schema::connection('business_1')->hasTable('company_settings'));
        $this->assertTrue(Schema::connection('business_2')->hasTable('company_settings'));
        $this->assertTrue(Schema::connection('business_1')->hasTable('bank_accounts'));
        $this->assertTrue(Schema::connection('business_1')->hasTable('bank_account_defaults'));
        $this->assertTrue(Schema::connection('business_1')->hasTable('clients'));
        $this->assertTrue(Schema::connection('business_2')->hasTable('bank_accounts'));
        $this->assertTrue(Schema::connection('business_2')->hasTable('bank_account_defaults'));
        $this->assertTrue(Schema::connection('business_2')->hasTable('clients'));
        foreach (['document_sequences', 'document_sequence_defaults', 'document_number_allocations'] as $table) {
            $this->assertTrue(Schema::connection('business_1')->hasTable($table));
            $this->assertTrue(Schema::connection('business_2')->hasTable($table));
            $this->assertFalse(Schema::connection('central')->hasTable($table));
        }
        $issuanceColumns = [
            'document_number', 'document_sequence_id', 'document_number_allocation_id',
            'issued_revision_id', 'issued_at', 'issue_correlation_uuid',
        ];
        foreach (['business_1', 'business_2'] as $connection) {
            $this->assertTrue(Schema::connection($connection)->hasColumns('invoices', $issuanceColumns));
            $this->assertFalse(Schema::connection($connection)->hasColumn('invoices', 'business_id'));
            $foreignKeys = collect(Schema::connection($connection)->getForeignKeys('invoices'))->pluck('name');
            $this->assertContains('invoices_issued_revision_foreign', $foreignKeys);
            $this->assertContains('invoices_allocation_link_foreign', $foreignKeys);
        }
        $this->assertFalse(Schema::connection('central')->hasTable('invoices'));
        $this->assertTrue(Schema::connection('business_1')->hasTable('audit_logs'));
        $this->assertTrue(Schema::connection('business_2')->hasTable('audit_logs'));
        $this->assertFalse(Schema::connection('central')->hasTable('audit_logs'));
        $this->assertFalse(Schema::connection('central')->hasTable('company_settings'));
        $this->assertFalse(Schema::connection('central')->hasTable('bank_accounts'));
        $this->assertFalse(Schema::connection('central')->hasTable('bank_account_defaults'));
        $this->assertFalse(Schema::connection('central')->hasTable('clients'));
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_business_databases_receive_identical_company_settings_schema(): void
    {
        $this->assertSame(0, Artisan::call('app:migrate-businesses'));

        $firstSchema = $this->normalizedColumns('business_1', 'company_settings');
        $secondSchema = $this->normalizedColumns('business_2', 'company_settings');

        $this->assertSame($firstSchema, $secondSchema);
        $this->assertSame([
            'id',
            'singleton_key',
            'legal_name',
            'additional_name',
            'registration_number',
            'tax_id',
            'vat_id',
            'street',
            'house_number',
            'orientation_number',
            'city',
            'postal_code',
            'country_code',
            'email',
            'phone',
            'website',
            'default_currency',
            'document_locale',
            'timezone',
            'is_vat_payer',
            'vat_registered_on',
            'default_due_days',
            'default_payment_method',
            'invoice_intro',
            'invoice_outro',
            'created_at',
            'updated_at',
        ], array_column($firstSchema, 'name'));
    }

    public function test_business_databases_receive_identical_bank_account_schema(): void
    {
        $this->assertSame(0, Artisan::call('app:migrate-businesses'));

        $accountColumns = [
            'id',
            'uuid',
            'name',
            'domestic_prefix',
            'domestic_account_number',
            'bank_code',
            'iban',
            'bic',
            'currency',
            'is_active',
            'sort_order',
            'note',
            'archived_at',
            'created_at',
            'updated_at',
        ];
        $defaultColumns = ['currency', 'bank_account_id', 'created_at', 'updated_at'];

        $this->assertSame(
            $this->normalizedColumns('business_1', 'bank_accounts'),
            $this->normalizedColumns('business_2', 'bank_accounts'),
        );
        $this->assertSame(
            $this->normalizedColumns('business_1', 'bank_account_defaults'),
            $this->normalizedColumns('business_2', 'bank_account_defaults'),
        );
        $this->assertSame(
            $accountColumns,
            array_column($this->normalizedColumns('business_1', 'bank_accounts'), 'name'),
        );
        $this->assertSame(
            $defaultColumns,
            array_column($this->normalizedColumns('business_1', 'bank_account_defaults'), 'name'),
        );

        foreach (['business_1', 'business_2'] as $connection) {
            $foreignKey = DB::connection($connection)->selectOne(
                <<<'SQL'
                    SELECT
                        COUNT(*) AS column_count,
                        MIN(REFERENCED_TABLE_SCHEMA) AS referenced_schema,
                        MIN(REFERENCED_TABLE_NAME) AS referenced_table
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'bank_account_defaults'
                      AND CONSTRAINT_NAME = 'bank_account_defaults_account_currency_foreign'
                    SQL,
            );

            $this->assertSame(2, (int) $foreignKey->column_count);
            $this->assertSame(
                DB::connection($connection)->getDatabaseName(),
                $foreignKey->referenced_schema,
            );
            $this->assertSame('bank_accounts', $foreignKey->referenced_table);
        }
    }

    public function test_business_databases_receive_identical_clients_schema(): void
    {
        $this->assertSame(0, Artisan::call('app:migrate-businesses'));

        $firstSchema = $this->normalizedColumns('business_1', 'clients');
        $secondSchema = $this->normalizedColumns('business_2', 'clients');

        $this->assertSame($firstSchema, $secondSchema);
        $this->assertSame([
            'id', 'uuid', 'type', 'display_name', 'company_name', 'first_name',
            'last_name', 'registration_number', 'tax_id', 'vat_id', 'email',
            'phone', 'website', 'contact_person', 'street', 'house_number',
            'orientation_number', 'city', 'postal_code', 'country_code',
            'delivery_name', 'delivery_street', 'delivery_house_number',
            'delivery_orientation_number', 'delivery_city', 'delivery_postal_code',
            'delivery_country_code', 'default_currency', 'default_due_days',
            'default_payment_method', 'language', 'note', 'is_active',
            'archived_at', 'created_at', 'updated_at',
        ], array_column($firstSchema, 'name'));
        $this->assertFalse(Schema::connection('central')->hasTable('clients'));
    }

    public function test_business_databases_receive_identical_document_sequence_schema(): void
    {
        $this->assertSame(0, Artisan::call('app:migrate-businesses'));

        $expectedColumns = [
            'document_sequences' => [
                'id', 'uuid', 'document_type', 'name', 'prefix', 'suffix',
                'year_format', 'sequence_digits', 'start_number', 'next_number',
                'reset_period', 'current_period', 'is_active', 'sort_order',
                'archived_at', 'created_at', 'updated_at',
            ],
            'document_sequence_defaults' => [
                'document_type', 'document_sequence_id', 'created_at', 'updated_at',
            ],
            'document_number_allocations' => [
                'id', 'correlation_uuid', 'document_sequence_id', 'document_type',
                'period', 'sequence_number', 'formatted_number', 'allocated_at',
                'document_uuid', 'created_at', 'updated_at',
            ],
        ];

        foreach ($expectedColumns as $table => $columns) {
            $first = $this->normalizedColumns('business_1', $table);
            $second = $this->normalizedColumns('business_2', $table);

            $this->assertSame($first, $second);
            $this->assertSame($columns, array_column($first, 'name'));
            $this->assertFalse(Schema::connection('central')->hasTable($table));
        }

        foreach (['business_1', 'business_2'] as $connection) {
            foreach ([
                'document_sequence_defaults_sequence_type_foreign' => 'document_sequence_defaults',
                'document_allocations_sequence_type_foreign' => 'document_number_allocations',
            ] as $constraint => $table) {
                $foreignKey = DB::connection($connection)->selectOne(
                    'SELECT COUNT(*) AS column_count, MIN(REFERENCED_TABLE_SCHEMA) AS referenced_schema,
                            MIN(REFERENCED_TABLE_NAME) AS referenced_table
                       FROM information_schema.KEY_COLUMN_USAGE
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    [$table, $constraint],
                );

                $this->assertSame(2, (int) $foreignKey->column_count);
                $this->assertSame(DB::connection($connection)->getDatabaseName(), $foreignKey->referenced_schema);
                $this->assertSame('document_sequences', $foreignKey->referenced_table);
            }
        }
    }

    public function test_business_databases_receive_identical_business_audit_schema_without_foreign_keys(): void
    {
        $this->assertSame(0, Artisan::call('app:migrate-businesses'));
        $first = $this->normalizedColumns('business_1', 'audit_logs');
        $second = $this->normalizedColumns('business_2', 'audit_logs');

        $this->assertSame($first, $second);
        $this->assertSame([
            'id', 'uuid', 'event', 'actor_user_uuid', 'actor_name', 'actor_email',
            'auditable_type', 'auditable_uuid', 'subject_type', 'subject_uuid',
            'old_values', 'new_values', 'changed_fields', 'metadata', 'request_id',
            'ip_address', 'user_agent', 'occurred_at', 'created_at', 'updated_at',
        ], array_column($first, 'name'));
        $this->assertFalse(Schema::connection('central')->hasTable('audit_logs'));

        foreach (['business_1', 'business_2'] as $connection) {
            $foreignKeys = DB::connection($connection)->selectOne(
                "SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            );
            $this->assertSame(0, (int) $foreignKeys->aggregate);
        }
    }

    public function test_business_databases_receive_identical_vat_rate_schema_and_local_foreign_key(): void
    {
        $this->assertSame(0, Artisan::call('app:migrate-businesses'));
        $expected = [
            'vat_rates' => ['id', 'uuid', 'name', 'code', 'tax_type', 'percentage', 'valid_from', 'valid_to', 'is_active', 'sort_order', 'archived_at', 'created_at', 'updated_at'],
            'vat_rate_defaults' => ['context', 'vat_rate_id', 'created_at', 'updated_at'],
        ];

        foreach ($expected as $table => $columns) {
            $first = $this->normalizedColumns('business_1', $table);
            $second = $this->normalizedColumns('business_2', $table);
            $this->assertSame($first, $second);
            $this->assertSame($columns, array_column($first, 'name'));
            $this->assertFalse(Schema::connection('central')->hasTable($table));
        }

        foreach (['business_1', 'business_2'] as $connection) {
            $foreign = DB::connection($connection)->selectOne(
                "SELECT COUNT(*) AS aggregate, MIN(REFERENCED_TABLE_SCHEMA) AS referenced_schema
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vat_rate_defaults'
                   AND REFERENCED_TABLE_NAME = 'vat_rates'",
            );
            $this->assertSame(1, (int) $foreign->aggregate);
            $this->assertSame(DB::connection($connection)->getDatabaseName(), $foreign->referenced_schema);
        }
    }

    public function test_business_databases_receive_identical_invoice_snapshot_schema_only_locally(): void
    {
        $this->assertSame(0, Artisan::call('app:migrate-businesses'));
        $tables = [
            'invoices', 'invoice_supplier_snapshots', 'invoice_customer_snapshots',
            'invoice_bank_account_snapshots', 'invoice_vat_snapshots', 'invoice_items',
            'invoice_revisions', 'invoice_vat_summaries', 'invoice_draft_operations',
        ];

        foreach ($tables as $table) {
            $this->assertSame(
                $this->normalizedColumns('business_1', $table),
                $this->normalizedColumns('business_2', $table),
            );
            $this->assertFalse(Schema::connection('central')->hasTable($table));
            $this->assertNotContains('business_id', array_column($this->normalizedColumns('business_1', $table), 'name'));
        }

        foreach (['business_1', 'business_2'] as $connection) {
            $foreignSchemas = DB::connection($connection)->select(
                "SELECT DISTINCT REFERENCED_TABLE_SCHEMA AS referenced_schema
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'invoice%'
                   AND REFERENCED_TABLE_SCHEMA IS NOT NULL",
            );
            $this->assertNotEmpty($foreignSchemas);
            foreach ($foreignSchemas as $foreign) {
                $this->assertSame(DB::connection($connection)->getDatabaseName(), $foreign->referenced_schema);
            }

            $triggers = DB::connection($connection)->selectOne(
                "SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN
                 ('invoice_revisions', 'invoice_supplier_snapshots', 'invoice_customer_snapshots',
                  'invoice_bank_account_snapshots', 'invoice_vat_snapshots', 'invoice_items',
                  'invoice_vat_summaries', 'invoice_draft_operations')",
            );
            $this->assertSame(17, (int) $triggers->aggregate);
        }
    }

    public function test_invoice_revision_migration_preserves_and_converts_existing_part_one_draft(): void
    {
        $this->resetBusinessConnection('business_1');
        $this->runMigrationsInNewBatch('business_1', self::PART_ONE_MIGRATIONS);

        $database = DB::connection('business_1');
        $now = now();
        $invoiceId = $database->table('invoices')->insertGetId([
            'uuid' => (string) Str::uuid(), 'document_type' => 'issued_invoice', 'status' => 'draft',
            'currency' => 'CZK', 'issued_on' => '2026-08-01', 'taxable_supply_on' => '2026-08-01',
            'due_on' => '2026-08-15', 'payment_method' => 'bank_transfer', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $database->table('invoice_supplier_snapshots')->insert([
            'invoice_id' => $invoiceId, 'legal_name' => 'Historický dodavatel', 'registration_number' => '12345678',
            'street' => 'Dodavatelská 1', 'city' => 'Praha', 'postal_code' => '11000', 'country_code' => 'CZ',
            'email' => 'supplier@example.test', 'is_vat_payer' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $database->table('invoice_customer_snapshots')->insert([
            'invoice_id' => $invoiceId, 'source_client_uuid' => (string) Str::uuid(), 'client_type' => 'company',
            'display_name' => 'Historický klient', 'street' => 'Klientská 2', 'city' => 'Brno',
            'postal_code' => '60200', 'country_code' => 'CZ', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $vatSnapshotId = $database->table('invoice_vat_snapshots')->insertGetId([
            'uuid' => (string) Str::uuid(), 'invoice_id' => $invoiceId, 'source_vat_rate_uuid' => (string) Str::uuid(),
            'name' => 'Základní', 'code' => 'STD', 'tax_type' => 'standard', 'percentage' => '21.0000',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $database->table('invoice_items')->insert([
            'uuid' => (string) Str::uuid(), 'invoice_id' => $invoiceId, 'invoice_vat_snapshot_id' => $vatSnapshotId,
            'position' => 1, 'description' => 'Převedená položka', 'quantity' => '2.5000', 'unit' => 'ks',
            'unit_price' => '100.0000', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $revisionBatch = $this->runMigrationsInNewBatch('business_1', [self::REVISION_MIGRATION]);

        $invoice = $database->table('invoices')->where('id', $invoiceId)->first();
        $revision = $database->table('invoice_revisions')->where('invoice_id', $invoiceId)->first();
        $item = $database->table('invoice_items')->where('invoice_revision_id', $revision->id)->first();
        $this->assertSame(1, (int) $invoice->version);
        $this->assertSame((int) $revision->id, (int) $invoice->current_revision_id);
        $this->assertSame(1, (int) $revision->revision_number);
        $this->assertSame('draft', $invoice->status);
        $this->assertFalse(Schema::connection('business_1')->hasColumn('invoices', 'document_number'));
        $this->assertFalse(Schema::connection('business_1')->hasColumn('invoices', 'issued_revision_id'));
        $this->assertSame('250.0000', $revision->tax_base_total);
        $this->assertSame('52.5000', $revision->vat_total);
        $this->assertSame('302.5000', $revision->grand_total);
        $this->assertSame('none', $item->discount_type);
        $this->assertSame('250.0000', $item->line_net_amount);
        $this->assertSame(1, $database->table('invoice_vat_summaries')->count());
        $this->assertSame('Historický klient', $database->table('invoice_customer_snapshots')->value('display_name'));

        $this->purgeInvoiceRevisionFixtures('business_1');
        $this->rollbackExpectedBatch('business_1', self::REVISION_MIGRATION, $revisionBatch);

        $this->assertFalse(Schema::connection('business_1')->hasTable('invoice_revisions'));
        $this->assertFalse(Schema::connection('business_1')->hasTable('invoice_vat_summaries'));
        $this->assertFalse(Schema::connection('business_1')->hasTable('invoice_draft_operations'));
        $this->assertFalse(Schema::connection('business_1')->hasColumn('invoices', 'current_revision_id'));
        $this->assertFalse(Schema::connection('business_1')->hasColumn('invoices', 'version'));
        foreach ([
            'invoice_supplier_snapshots', 'invoice_customer_snapshots', 'invoice_bank_account_snapshots',
            'invoice_vat_snapshots', 'invoice_items',
        ] as $table) {
            $this->assertTrue(Schema::connection('business_1')->hasColumn($table, 'invoice_id'));
            $this->assertFalse(Schema::connection('business_1')->hasColumn($table, 'invoice_revision_id'));
        }
    }

    public function test_command_can_migrate_only_one_enum_connection(): void
    {
        $this->assertSame(0, Artisan::call('app:migrate-businesses', [
            '--business' => 'business_1',
        ]));

        $this->assertTrue(Schema::connection('business_1')->hasTable('company_settings'));
        $this->assertTrue(Schema::connection('business_1')->hasTable('bank_accounts'));
        $this->assertTrue(Schema::connection('business_1')->hasTable('clients'));
        $this->assertTrue(Schema::connection('business_1')->hasTable('document_sequences'));
        $this->assertTrue(Schema::connection('business_1')->hasTable('audit_logs'));
        $this->assertTrue(Schema::connection('business_1')->hasTable('vat_rates'));
        $this->assertTrue(Schema::connection('business_1')->hasTable('vat_rate_defaults'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('company_settings'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('bank_accounts'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('clients'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('document_sequences'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('audit_logs'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('vat_rates'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('vat_rate_defaults'));
        $this->assertFalse(Schema::connection('central')->hasTable('company_settings'));
    }

    public function test_invoice_issuance_migration_can_roll_back_without_issued_documents(): void
    {
        $this->resetBusinessConnection('business_1');
        $this->runMigrationsInNewBatch('business_1', [
            ...self::PART_ONE_MIGRATIONS,
            self::REVISION_MIGRATION,
        ]);
        $issuanceBatch = $this->runMigrationsInNewBatch('business_1', [self::ISSUANCE_MIGRATION]);

        $this->assertTrue(Schema::connection('business_1')->hasColumn('invoices', 'issued_revision_id'));
        $this->assertTrue($this->invoiceCheckConstraintExists('business_1', 'invoices_issuance_values_check'));
        $this->assertFalse($this->invoiceCheckConstraintExists('business_1', 'invoices_part_one_values_check'));

        $this->rollbackExpectedBatch('business_1', self::ISSUANCE_MIGRATION, $issuanceBatch);

        $this->assertFalse(Schema::connection('business_1')->hasColumn('invoices', 'issued_revision_id'));
        $this->assertFalse(Schema::connection('business_1')->hasColumn('invoices', 'document_number'));
        $this->assertTrue(Schema::connection('business_1')->hasColumn('document_number_allocations', 'document_uuid'));
        $this->assertFalse($this->invoiceCheckConstraintExists('business_1', 'invoices_issuance_values_check'));
        $this->assertTrue($this->invoiceCheckConstraintExists('business_1', 'invoices_part_one_values_check'));
        $this->assertSame('central', DB::getDefaultConnection());
    }

    #[DataProvider('invalidConnections')]
    public function test_command_rejects_non_business_connection(string $connection): void
    {
        $this->assertSame(1, Artisan::call('app:migrate-businesses', [
            '--business' => $connection,
        ]));

        $this->assertFalse(Schema::connection('business_1')->hasTable('company_settings'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('company_settings'));
        $this->assertFalse(Schema::connection('central')->hasTable('company_settings'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidConnections(): array
    {
        return [
            'central' => ['central'],
            'unknown connection' => ['business_3'],
        ];
    }

    public function test_command_does_not_use_migrate_fresh_or_change_default_connection(): void
    {
        Schema::connection('business_1')->create('migration_sentinel', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::connection('business_1')->table('migration_sentinel')->insert(['value' => 'zachovat']);

        $this->assertSame(0, Artisan::call('app:migrate-businesses', [
            '--business' => 'business_1',
        ]));

        $this->assertTrue(Schema::connection('business_1')->hasTable('migration_sentinel'));
        $this->assertSame(
            'zachovat',
            DB::connection('business_1')->table('migration_sentinel')->value('value'),
        );
        $this->assertSame('central', DB::getDefaultConnection());
    }

    private function resetBusinessConnection(string $connection): void
    {
        $this->ensureSafeTestDatabases();
        $this->assertContains($connection, ['business_1', 'business_2']);
        $defaultConnection = DB::getDefaultConnection();

        $exitCode = Artisan::call('migrate:reset', [
            '--database' => $connection,
            '--path' => [database_path('migrations/business')],
            '--realpath' => true,
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(0, DB::connection($connection)->table('migrations')->count());
        $this->assertSame($defaultConnection, DB::getDefaultConnection());
    }

    /**
     * @param  list<string>  $migrations
     */
    private function runMigrationsInNewBatch(string $connection, array $migrations): int
    {
        $database = DB::connection($connection);
        $previousBatch = (int) ($database->table('migrations')->max('batch') ?? 0);
        $paths = array_map(
            static fn (string $migration): string => database_path("migrations/business/{$migration}.php"),
            $migrations,
        );

        $exitCode = Artisan::call('migrate', [
            '--database' => $connection,
            '--path' => $paths,
            '--realpath' => true,
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $batch = (int) $database->table('migrations')->max('batch');
        $this->assertSame($previousBatch + 1, $batch);

        $expectedMigrations = $migrations;
        sort($expectedMigrations);
        $this->assertSame(
            $expectedMigrations,
            $database->table('migrations')->where('batch', $batch)->orderBy('migration')->pluck('migration')->all(),
        );
        $this->assertSame('central', DB::getDefaultConnection());

        return $batch;
    }

    private function rollbackExpectedBatch(string $connection, string $migration, int $batch): void
    {
        $database = DB::connection($connection);
        $before = $database->table('migrations')->orderBy('migration')->pluck('batch', 'migration')->all();

        $this->assertArrayHasKey($migration, $before);
        $this->assertSame($batch, (int) $before[$migration]);
        $this->assertSame($batch, (int) $database->table('migrations')->max('batch'));
        $this->assertSame(
            [$migration],
            $database->table('migrations')->where('batch', $batch)->pluck('migration')->all(),
        );
        $this->assertArrayNotHasKey(self::DELIVERY_MIGRATION, $before);

        $exitCode = Artisan::call('migrate:rollback', [
            '--database' => $connection,
            '--path' => [database_path("migrations/business/{$migration}.php")],
            '--realpath' => true,
            '--batch' => $batch,
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());

        unset($before[$migration]);
        $this->assertSame(
            $before,
            $database->table('migrations')->orderBy('migration')->pluck('batch', 'migration')->all(),
        );
        $this->assertFalse($database->table('migrations')->where('migration', $migration)->exists());
        $this->assertFalse($database->table('migrations')->where('migration', self::DELIVERY_MIGRATION)->exists());
        $this->assertSame('central', DB::getDefaultConnection());
    }

    private function purgeInvoiceRevisionFixtures(string $connection): void
    {
        $this->ensureSafeTestDatabases();
        $database = DB::connection($connection);
        $database->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ([
                'invoice_draft_operations', 'invoice_vat_summaries', 'invoice_items',
                'invoice_supplier_snapshots', 'invoice_customer_snapshots',
                'invoice_bank_account_snapshots', 'invoice_vat_snapshots',
                'invoice_revisions', 'invoices',
            ] as $table) {
                $database->table($table)->truncate();
            }
        } finally {
            $database->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function invoiceCheckConstraintExists(string $connection, string $constraint): bool
    {
        $result = DB::connection($connection)->selectOne(
            <<<'SQL'
                SELECT COUNT(*) AS aggregate
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'invoices'
                  AND CONSTRAINT_NAME = ?
                  AND CONSTRAINT_TYPE = 'CHECK'
                SQL,
            [$constraint],
        );

        return (int) $result->aggregate === 1;
    }

    /**
     * @return list<array{name: string, type_name: string, nullable: bool, default: mixed, auto_increment: bool}>
     */
    private function normalizedColumns(string $connection, string $table): array
    {
        return array_map(
            static fn (array $column): array => Arr::only($column, [
                'name',
                'type_name',
                'nullable',
                'default',
                'auto_increment',
            ]),
            Schema::connection($connection)->getColumns($table),
        );
    }
}
