<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Enums\VatTaxType;
use App\Models\Business;
use App\Models\Business\VatRate;
use App\Models\Business\VatRateDefault;
use App\Services\Business\InvoiceVatResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceVatResolverTest extends TestCase
{
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

    public function test_non_payer_uses_only_system_non_payer_and_ignores_other_modes_and_sales_default(): void
    {
        $this->activate(BusinessConnection::Business1);
        $system = $this->systemRate();
        $standardZero = $this->rate('Starý neplátce', 'LEGACY-ZERO', 'standard', ['percentage' => '0.0000']);
        $out = $this->rate('Mimo předmět', 'OUT', 'out_of_scope');
        $this->rate('Osvobozené', 'EXEMPT', 'exempt');
        $default = new VatRateDefault;
        $default->forceFill(['context' => 'sales', 'vat_rate_id' => $out->id])->save();

        $resolved = $this->resolver()->resolve($this->items(), $this->date(), false);

        $this->assertSame($system->uuid, $resolved['items'][0]['vat_rate_uuid']);
        $this->assertSame($system->uuid, $resolved['items'][1]['vat_rate_uuid']);
        $this->assertSame([$system->uuid], array_keys($resolved['rates']));
        $this->assertNotSame($standardZero->uuid, $resolved['items'][0]['vat_rate_uuid']);
    }

    public function test_non_payer_fails_closed_without_system_rate_or_with_multiple_system_rates(): void
    {
        $this->activate(BusinessConnection::Business1);
        $this->systemRate()->delete();

        try {
            $this->resolver()->resolve($this->items(), $this->date(), false);
            $this->fail('Chybějící systémový režim neplátce měl být odmítnut.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->rate('První systémový', 'NON-PAYER-1', 'non_payer', ['valid_from' => '1000-01-01']);
        $this->rate('Druhý systémový', 'NON-PAYER-2', 'non_payer', ['valid_from' => '1000-01-01']);

        try {
            $this->resolver()->resolve($this->items(), $this->date(), false);
            $this->fail('Nejednoznačné systémové režimy neplátce měly být odmítnuty.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('více systémových režimů', $exception->errors()['items'][0]);
        }
    }

    public function test_inactive_archived_or_date_invalid_system_rate_has_no_fallback(): void
    {
        $this->activate(BusinessConnection::Business1);
        $system = $this->systemRate();
        $this->rate('Mimo předmět', 'OUT', 'out_of_scope');
        $this->rate('Osvobozené', 'EXEMPT', 'exempt');

        foreach ([
            'inactive' => ['is_active' => false, 'archived_at' => null, 'valid_to' => null],
            'archived' => ['is_active' => true, 'archived_at' => now(), 'valid_to' => null],
            'expired' => ['is_active' => true, 'archived_at' => null, 'valid_to' => '2026-07-31'],
        ] as $state => $attributes) {
            $system->forceFill($attributes)->save();

            try {
                $this->resolver()->resolve($this->items(), $this->date(), false);
                $this->fail('Nepoužitelný systémový režim měl být odmítnut: '.$state);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('items', $exception->errors(), $state);
            }
        }
    }

    public function test_vat_payer_cannot_use_system_non_payer_rate(): void
    {
        $this->activate(BusinessConnection::Business1);
        $system = $this->systemRate();
        $items = $this->items();
        $items[0]['vat_rate_uuid'] = $system->uuid;
        $items[1]['vat_rate_uuid'] = $system->uuid;

        $this->expectException(ValidationException::class);
        $this->resolver()->resolve($items, $this->date(), true);
    }

    public function test_resolution_is_isolated_by_active_business_database(): void
    {
        $this->activate(BusinessConnection::Business1);
        $first = $this->systemRate();
        $firstResolved = $this->resolver()->resolve($this->items(), $this->date(), false);

        $this->activate(BusinessConnection::Business2);
        $second = $this->systemRate();
        $secondResolved = $this->resolver()->resolve($this->items(), $this->date(), false);

        $this->assertSame($first->uuid, $firstResolved['items'][0]['vat_rate_uuid']);
        $this->assertSame($second->uuid, $secondResolved['items'][0]['vat_rate_uuid']);
        $this->assertNotSame($first->uuid, $second->uuid);
    }

    private function resolver(): InvoiceVatResolver
    {
        return app(InvoiceVatResolver::class);
    }

    private function date(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-02');
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        return [
            ['position' => 1, 'description' => 'Služba', 'quantity' => '1', 'unit_price' => '100'],
            ['position' => 2, 'description' => 'Materiál', 'quantity' => '1', 'unit_price' => '50'],
        ];
    }

    private function systemRate(): VatRate
    {
        return VatRate::query()->where('tax_type', VatTaxType::NonPayer->value)->sole();
    }

    /** @param array<string, mixed> $overrides */
    private function rate(string $name, string $code, string $taxType, array $overrides = []): VatRate
    {
        $rate = new VatRate;
        $rate->forceFill(array_replace([
            'name' => $name,
            'code' => $code,
            'tax_type' => $taxType,
            'percentage' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'is_active' => true,
            'sort_order' => 0,
            'archived_at' => null,
        ], $overrides))->save();

        return $rate;
    }

    private function activate(BusinessConnection $connection): void
    {
        $business = Business::query()->firstOrCreate(['connection_name' => $connection->connectionName()], [
            'uuid' => (string) Str::uuid(),
            'display_name' => $connection->connectionName(),
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection->connectionName(),
            'visual_identifier' => 'briefcase',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        app(ActiveBusinessContext::class)->set($business);
    }
}
