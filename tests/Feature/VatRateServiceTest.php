<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\Exceptions\MissingBusinessContext;
use App\Domain\Vat\Exceptions\VatRateUnavailable;
use App\Enums\BusinessConnection;
use App\Enums\VatRateDefaultContext;
use App\Models\Business;
use App\Models\Business\CompanySetting;
use App\Models\Business\VatRate;
use App\Models\Business\VatRateDefault;
use App\Services\Business\VatRateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBusinessProcessEnvironment;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class VatRateServiceTest extends TestCase
{
    use BuildsBusinessProcessEnvironment;
    use InteractsWithBusinessDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshBusinessTestDatabases();
    }

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_models_fail_closed_without_context(): void
    {
        app(ActiveBusinessContext::class)->clear();
        DB::connection('central')->enableQueryLog();

        foreach ([VatRate::class, VatRateDefault::class] as $model) {
            try {
                $model::query()->count();
                $this->fail('Model měl bez contextu selhat.');
            } catch (MissingBusinessContext) {
                $this->assertSame([], DB::connection('central')->getQueryLog());
            }
        }
    }

    public function test_rates_are_physically_isolated_and_uuid_is_server_generated(): void
    {
        $fakeUuid = (string) Str::uuid();
        $sharedUuid = (string) Str::uuid();
        $this->activate(BusinessConnection::Business1);
        $first = $this->service()->create($this->attributes(['uuid' => $fakeUuid, 'connection' => 'business_2']));
        $first->forceFill(['uuid' => $sharedUuid])->save();

        $this->activate(BusinessConnection::Business2);
        $second = $this->service()->create($this->attributes());
        $second->forceFill(['uuid' => $sharedUuid])->save();

        $this->assertNotSame($fakeUuid, $first->uuid);
        $this->assertSame('21.0000', $first->percentage);
        $this->assertSame(1, DB::connection('business_1')->table('vat_rates')->count());
        $this->assertSame(1, DB::connection('business_2')->table('vat_rates')->count());
        $this->assertFalse(Schema::connection('central')->hasTable('vat_rates'));
        $this->assertSame('central', DB::getDefaultConnection());
        $this->assertSame($first->uuid, $second->uuid);
    }

    public function test_tax_type_percentage_rules_are_enforced_by_service_and_database(): void
    {
        $this->activate(BusinessConnection::Business1);
        $zero = $this->service()->create($this->attributes(['code' => 'ZERO', 'tax_type' => 'zero', 'percentage' => '0']));
        $exempt = $this->service()->create($this->attributes(['code' => 'EXEMPT', 'tax_type' => 'exempt', 'percentage' => null]));
        $this->assertSame('0.0000', $zero->percentage);
        $this->assertNull($exempt->percentage);

        foreach ([
            ['standard', null], ['reduced', ''], ['zero', '1'], ['exempt', '0'],
            ['reverse_charge', '0'], ['out_of_scope', '0'],
        ] as [$type, $percentage]) {
            try {
                $this->service()->create($this->attributes([
                    'code' => 'BAD'.Str::random(6), 'tax_type' => $type, 'percentage' => $percentage,
                ]));
                $this->fail("Neplatná kombinace {$type} měla být odmítnuta.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(QueryException::class);
        DB::connection('business_1')->table('vat_rates')->insert([
            'uuid' => (string) Str::uuid(), 'name' => 'Neplatná', 'code' => 'DB-BAD',
            'tax_type' => 'exempt', 'percentage' => '21.0000', 'valid_from' => '2026-01-01',
            'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_inclusive_intervals_allow_adjacent_periods_and_reject_overlaps(): void
    {
        $this->activate(BusinessConnection::Business1);
        $first = $this->service()->create($this->attributes(['valid_from' => '2026-01-01', 'valid_to' => '2026-06-30']));
        $second = $this->service()->create($this->attributes(['valid_from' => '2026-07-01', 'valid_to' => null, 'percentage' => '22']));

        $this->assertSame($first->uuid, $this->service()->resolveForDate($first->uuid, CarbonImmutable::parse('2026-01-01'))->uuid);
        $this->assertSame($first->uuid, $this->service()->resolveForDate($first->uuid, CarbonImmutable::parse('2026-06-30'))->uuid);
        $this->assertSame($second->uuid, $this->service()->resolveForDate($second->uuid, CarbonImmutable::parse('2026-07-01'))->uuid);

        foreach ([
            ['2026-06-30', '2026-07-01'],
            ['2025-01-01', null],
            ['2026-07-01', '2026-07-01'],
        ] as [$from, $to]) {
            try {
                $this->service()->create($this->attributes(['valid_from' => $from, 'valid_to' => $to]));
                $this->fail('Překryv měl být odmítnut.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('valid_from', $exception->errors());
            }
        }

        $different = $this->service()->create($this->attributes(['code' => 'OTHER', 'valid_from' => '2026-01-01', 'valid_to' => null]));
        $this->assertSame('OTHER', $different->code);

        $singleDay = $this->service()->create($this->attributes([
            'code' => 'SINGLE', 'valid_from' => '2026-08-01', 'valid_to' => '2026-08-01',
        ]));
        $this->assertSame($singleDay->uuid, $this->service()->resolveForDate(
            $singleDay->uuid,
            CarbonImmutable::parse('2026-08-01'),
        )->uuid);

        try {
            $this->service()->create($this->attributes([
                'code' => 'BACKWARDS', 'valid_from' => '2026-08-02', 'valid_to' => '2026-08-01',
            ]));
            $this->fail('Obrácený interval měl být odmítnut.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('valid_to', $exception->errors());
        }
    }

    public function test_resolution_rejects_outside_inactive_and_archived_rates(): void
    {
        $this->activate(BusinessConnection::Business1);
        $rate = $this->service()->create($this->attributes(['valid_from' => '2026-02-01', 'valid_to' => '2026-02-28']));

        foreach (['2026-01-31', '2026-03-01'] as $date) {
            try {
                $this->service()->resolveForDate($rate->uuid, CarbonImmutable::parse($date));
                $this->fail('Datum mimo interval mělo selhat.');
            } catch (VatRateUnavailable) {
                $this->assertTrue(true);
            }
        }

        $this->service()->deactivate($rate->uuid);
        try {
            $this->service()->resolveForDate($rate->uuid, CarbonImmutable::parse('2026-02-15'));
            $this->fail('Neaktivní sazba neměla být dostupná.');
        } catch (VatRateUnavailable) {
            $this->assertTrue(true);
        }

        $this->service()->activate($rate->uuid);
        $this->service()->archive($rate->uuid);
        $this->expectException(VatRateUnavailable::class);
        $this->service()->resolveForDate($rate->uuid, CarbonImmutable::parse('2026-02-15'));
    }

    public function test_default_is_atomic_date_safe_and_respects_non_payer(): void
    {
        $this->activate(BusinessConnection::Business1);
        $standard = $this->service()->create($this->attributes());
        $out = $this->service()->create($this->attributes([
            'code' => 'OUT', 'tax_type' => 'out_of_scope', 'percentage' => null,
        ]));
        $exempt = $this->service()->create($this->attributes([
            'code' => 'EX', 'tax_type' => 'exempt', 'percentage' => null,
        ]));

        try {
            $this->service()->setDefault($standard->uuid);
            $this->fail('Neplátce neměl nastavit standardní sazbu.');
        } catch (ValidationException) {
            $this->assertSame(0, VatRateDefault::query()->count());
        }

        $this->service()->setDefault($out->uuid);
        $this->service()->setDefault($exempt->uuid);
        $this->assertSame(1, VatRateDefault::query()->count());
        $this->assertSame($exempt->uuid, $this->service()->resolveDefaultForDate(
            VatRateDefaultContext::Sales,
            CarbonImmutable::parse('2026-02-01'),
        )->uuid);

        $this->setVatPayer(true);
        $this->service()->setDefault($standard->uuid);
        $this->assertSame($standard->id, VatRateDefault::query()->first()->vat_rate_id);
    }

    public function test_database_allows_only_one_default_row_for_sales(): void
    {
        $this->activate(BusinessConnection::Business1);
        $first = $this->service()->create($this->attributes(['code' => 'ONE', 'tax_type' => 'out_of_scope', 'percentage' => null]));
        $second = $this->service()->create($this->attributes(['code' => 'TWO', 'tax_type' => 'out_of_scope', 'percentage' => null]));
        $this->service()->setDefault($first->uuid);

        $this->expectException(QueryException::class);
        DB::connection('business_1')->table('vat_rate_defaults')->insert([
            'context' => 'sales', 'vat_rate_id' => $second->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_default_is_removed_on_deactivation_and_archive_and_archive_is_one_way(): void
    {
        $this->activate(BusinessConnection::Business1);
        $rate = $this->service()->create($this->attributes(['tax_type' => 'out_of_scope', 'percentage' => null]));
        $this->service()->setDefault($rate->uuid);
        $this->service()->deactivate($rate->uuid);
        $this->assertSame(0, VatRateDefault::query()->count());

        $this->service()->activate($rate->uuid);
        $this->service()->setDefault($rate->uuid);
        $archived = $this->service()->archive($rate->uuid);
        $archivedAt = $archived->archived_at->toDateTimeString();
        $this->assertSame(0, VatRateDefault::query()->count());
        $this->assertFalse($archived->is_active);

        try {
            $this->service()->archive($rate->uuid);
            $this->fail('Opakovaná archivace měla vrátit 404 chování.');
        } catch (ModelNotFoundException) {
            $this->assertSame($archivedAt, $rate->fresh()->archived_at->toDateTimeString());
        }

        $this->expectException(ValidationException::class);
        $this->service()->activate($rate->uuid);
    }

    public function test_all_audit_events_are_safe_and_no_change_update_is_silent(): void
    {
        $this->activate(BusinessConnection::Business1);
        $rate = $this->service()->create($this->attributes(['tax_type' => 'out_of_scope', 'percentage' => null]));
        $this->service()->update($rate->uuid, $this->attributes(['tax_type' => 'out_of_scope', 'percentage' => null]));
        $this->service()->setDefault($rate->uuid);
        $this->service()->deactivate($rate->uuid);
        $this->service()->activate($rate->uuid);
        $this->service()->update($rate->uuid, $this->attributes(['name' => 'Upravená', 'tax_type' => 'out_of_scope', 'percentage' => null]));
        $this->service()->archive($rate->uuid);

        $events = DB::connection('business_1')->table('audit_logs')->pluck('event')->all();
        foreach (['vat_rate.created', 'vat_rate.default_changed', 'vat_rate.default_removed', 'vat_rate.deactivated', 'vat_rate.activated', 'vat_rate.updated', 'vat_rate.archived'] as $event) {
            $this->assertContains($event, $events);
        }
        $this->assertSame(1, array_count_values($events)['vat_rate.updated']);
        $payload = DB::connection('business_1')->table('audit_logs')->pluck('new_values')->implode(' ');
        $this->assertStringNotContainsString('business_1', $payload);
        $this->assertSame(0, DB::connection('business_2')->table('audit_logs')->count());
    }

    public function test_audit_failure_rolls_back_domain_change(): void
    {
        $this->activate(BusinessConnection::Business1);
        Schema::connection('business_1')->drop('audit_logs');

        try {
            $this->service()->create($this->attributes());
            $this->fail('Chybějící auditní tabulka měla způsobit rollback.');
        } catch (QueryException) {
            $this->assertSame(0, DB::connection('business_1')->table('vat_rates')->count());
        }
    }

    public function test_two_real_processes_cannot_create_overlapping_periods(): void
    {
        $this->activate(BusinessConnection::Business1);
        $barrier = storage_path('framework/testing/vat-rate-'.Str::uuid());
        $processes = [];

        for ($i = 0; $i < 2; $i++) {
            $processes[] = new Process([
                PHP_BINARY, base_path('tests/Support/create-vat-rate.php'),
                'business_1', 'CONCURRENT', $barrier,
            ], base_path(), $this->businessChildProcessEnvironment());
        }

        try {
            foreach ($processes as $process) {
                $process->setTimeout(20);
                $process->start();
            }
            file_put_contents($barrier, 'start');
            foreach ($processes as $process) {
                $process->wait();
            }
        } finally {
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }

        $this->assertSame(1, collect($processes)->filter->isSuccessful()->count());
        $this->assertSame(1, VatRate::query()->where('code', 'CONCURRENT')->count());
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'vat_rate.created')->count());
    }

    private function activate(BusinessConnection $connection): Business
    {
        $business = Business::query()->create([
            'uuid' => (string) Str::uuid(), 'display_name' => $connection->connectionName(),
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection->connectionName(), 'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(), 'is_active' => true, 'sort_order' => 1,
        ]);
        app(ActiveBusinessContext::class)->set($business);

        return $business;
    }

    private function setVatPayer(bool $payer): void
    {
        $setting = new CompanySetting;
        $setting->forceFill([
            'singleton_key' => CompanySetting::SINGLETON_KEY, 'legal_name' => 'Test',
            'registration_number' => '12345678', 'street' => '', 'city' => '', 'postal_code' => '',
            'country_code' => 'CZ', 'email' => '', 'default_currency' => 'CZK',
            'document_locale' => 'cs', 'timezone' => 'Europe/Prague', 'is_vat_payer' => $payer,
            'default_due_days' => 14, 'default_payment_method' => 'bank_transfer',
        ])->save();
    }

    private function service(): VatRateService
    {
        return app(VatRateService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function attributes(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Základní sazba', 'code' => 'CZ-STANDARD', 'tax_type' => 'standard',
            'percentage' => '21', 'valid_from' => '2026-01-01', 'valid_to' => null,
            'is_active' => true, 'sort_order' => 10,
        ], $overrides);
    }
}
