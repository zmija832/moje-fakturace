<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class BusinessMigrationCommandTest extends TestCase
{
    use InteractsWithBusinessDatabases;

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
        $this->assertFalse(Schema::connection('business_2')->hasTable('company_settings'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('bank_accounts'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('clients'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('document_sequences'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('audit_logs'));
        $this->assertFalse(Schema::connection('central')->hasTable('company_settings'));
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
