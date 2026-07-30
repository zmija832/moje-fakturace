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
        $this->assertFalse(Schema::connection('central')->hasTable('company_settings'));
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_business_databases_receive_identical_company_settings_schema(): void
    {
        $this->assertSame(0, Artisan::call('app:migrate-businesses'));

        $firstSchema = $this->normalizedColumns('business_1');
        $secondSchema = $this->normalizedColumns('business_2');

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

    public function test_command_can_migrate_only_one_enum_connection(): void
    {
        $this->assertSame(0, Artisan::call('app:migrate-businesses', [
            '--business' => 'business_1',
        ]));

        $this->assertTrue(Schema::connection('business_1')->hasTable('company_settings'));
        $this->assertFalse(Schema::connection('business_2')->hasTable('company_settings'));
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
    private function normalizedColumns(string $connection): array
    {
        return array_map(
            static fn (array $column): array => Arr::only($column, [
                'name',
                'type_name',
                'nullable',
                'default',
                'auto_increment',
            ]),
            Schema::connection($connection)->getColumns('company_settings'),
        );
    }
}
