<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\Exceptions\MissingBusinessContext;
use App\Enums\BusinessConnection;
use App\Enums\DefaultPaymentMethod;
use App\Models\Business;
use App\Models\Business\CompanySetting;
use App\Services\Business\CompanySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class CompanySettingModelTest extends TestCase
{
    use InteractsWithBusinessDatabases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshBusinessTestDatabases();
    }

    protected function tearDown(): void
    {
        try {
            $this->ensureSafeTestDatabases();

            foreach (BusinessConnection::cases() as $connection) {
                if (Schema::connection($connection->connectionName())->hasTable('company_settings')) {
                    DB::connection($connection->connectionName())->table('company_settings')->delete();
                }
            }

            app(ActiveBusinessContext::class)->clear();
        } finally {
            parent::tearDown();
        }
    }

    public function test_model_without_context_fails_before_sql_query(): void
    {
        app(ActiveBusinessContext::class)->clear();
        DB::connection('central')->flushQueryLog();
        DB::connection('central')->enableQueryLog();

        try {
            CompanySetting::query()->count();
            $this->fail('CompanySetting bez contextu měl selhat.');
        } catch (MissingBusinessContext) {
            $this->assertSame([], DB::connection('central')->getQueryLog());
        }
    }

    public function test_service_returns_unsaved_safe_defaults_without_writing_on_read(): void
    {
        $this->activateBusiness(BusinessConnection::Business1, 'Výchozí subjekt', '12345678');

        $setting = app(CompanySettingsService::class)->forForm();

        $this->assertFalse($setting->exists);
        $this->assertSame('Výchozí subjekt', $setting->legal_name);
        $this->assertSame('12345678', $setting->registration_number);
        $this->assertSame('CZ', $setting->country_code);
        $this->assertSame('CZK', $setting->default_currency);
        $this->assertSame(14, $setting->default_due_days);
        $this->assertFalse($setting->is_vat_payer);
        $this->assertSame(0, DB::connection('business_1')->table('company_settings')->count());
    }

    public function test_company_settings_use_active_connection_and_are_physically_isolated(): void
    {
        $this->activateBusiness(BusinessConnection::Business1, 'První subjekt', '12345678');
        $first = app(CompanySettingsService::class)->save(
            $this->attributes(['legal_name' => 'Nastavení business 1']),
        );
        $this->assertSame('business_1', $first->getConnectionName());

        $this->activateBusiness(BusinessConnection::Business2, 'Druhý subjekt', '87654321');
        $second = app(CompanySettingsService::class)->save(
            $this->attributes([
                'legal_name' => 'Nastavení business 2',
                'registration_number' => '87654321',
            ]),
        );

        $this->assertSame('business_2', $second->getConnectionName());
        $this->assertSame(
            'Nastavení business 1',
            DB::connection('business_1')->table('company_settings')->value('legal_name'),
        );
        $this->assertSame(
            'Nastavení business 2',
            DB::connection('business_2')->table('company_settings')->value('legal_name'),
        );
        $this->assertFalse(Schema::connection('central')->hasTable('company_settings'));
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_database_constraints_prevent_second_singleton_even_with_another_key(): void
    {
        $this->activateBusiness(BusinessConnection::Business1, 'První subjekt', '12345678');
        app(CompanySettingsService::class)->save($this->attributes());

        $second = new CompanySetting;
        $second->forceFill(['singleton_key' => '2']);
        $second->fill($this->attributes(['legal_name' => 'Druhý řádek']));

        try {
            $second->save();
            $this->fail('Databáze měla odmítnout druhý singleton řádek.');
        } catch (QueryException) {
            $this->assertSame(1, CompanySetting::query()->count());
            $this->assertSame(CompanySetting::SINGLETON_KEY, CompanySetting::query()->value('singleton_key'));
        }
    }

    public function test_model_casts_company_setting_values(): void
    {
        $this->activateBusiness(BusinessConnection::Business1, 'První subjekt', '12345678');
        $setting = app(CompanySettingsService::class)->save($this->attributes([
            'is_vat_payer' => true,
            'vat_id' => 'CZ12345678',
            'vat_registered_on' => '2025-01-02',
            'default_due_days' => 30,
        ]));

        $this->assertTrue($setting->is_vat_payer);
        $this->assertSame('2025-01-02', $setting->vat_registered_on->format('Y-m-d'));
        $this->assertSame(30, $setting->default_due_days);
    }

    private function activateBusiness(
        BusinessConnection $connection,
        string $name,
        string $registrationNumber,
    ): void {
        $business = Business::query()->create([
            'uuid' => (string) Str::uuid(),
            'display_name' => $name,
            'registration_number' => $registrationNumber,
            'short_label' => $name,
            'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(),
            'is_active' => true,
            'sort_order' => $connection === BusinessConnection::Business1 ? 1 : 2,
        ]);

        app(ActiveBusinessContext::class)->set($business);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(array $overrides = []): array
    {
        return array_replace([
            'legal_name' => 'Testovací subjekt',
            'additional_name' => null,
            'registration_number' => '12345678',
            'tax_id' => 'CZ12345678',
            'vat_id' => null,
            'street' => 'Testovací',
            'house_number' => '1',
            'orientation_number' => null,
            'city' => 'Praha',
            'postal_code' => '11000',
            'country_code' => 'CZ',
            'email' => 'firma@example.test',
            'phone' => '+420 123 456 789',
            'website' => 'https://example.test',
            'default_currency' => 'CZK',
            'document_locale' => 'cs',
            'timezone' => 'Europe/Prague',
            'is_vat_payer' => false,
            'vat_registered_on' => null,
            'default_due_days' => 14,
            'default_payment_method' => DefaultPaymentMethod::BankTransfer->value,
            'invoice_intro' => null,
            'invoice_outro' => null,
        ], $overrides);
    }
}
