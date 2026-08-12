<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
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

    public function test_non_payer_prefers_usable_sales_default_without_guessing_by_code(): void
    {
        $this->activate(BusinessConnection::Business1);
        $out = $this->rate('Režim A', 'LIBOVOLNY-A', 'out_of_scope');
        $exempt = $this->rate('Režim B', 'LIBOVOLNY-B', 'exempt');
        $this->setDefault($exempt);

        $resolved = $this->resolver()->resolve($this->items(), $this->date(), false);

        $this->assertSame($exempt->uuid, $resolved['items'][0]['vat_rate_uuid']);
        $this->assertSame([$exempt->uuid], array_keys($resolved['rates']));
        $this->assertNotSame($out->uuid, $resolved['items'][0]['vat_rate_uuid']);
    }

    public function test_non_payer_uses_only_valid_candidate_without_default(): void
    {
        $this->activate(BusinessConnection::Business1);
        $rate = $this->rate('Jediný režim', 'JEDINY', 'exempt');

        $resolved = $this->resolver()->resolve($this->items(), $this->date(), false);

        $this->assertSame($rate->uuid, $resolved['items'][0]['vat_rate_uuid']);
        $this->assertSame($rate->uuid, $resolved['items'][1]['vat_rate_uuid']);
    }

    public function test_non_payer_fails_closed_without_candidate_or_with_ambiguous_candidates(): void
    {
        $this->activate(BusinessConnection::Business1);

        try {
            $this->resolver()->resolve($this->items(), $this->date(), false);
            $this->fail('Chybějící režim neplátce měl být odmítnut.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->rate('První', 'PRVNI', 'out_of_scope');
        $this->rate('Druhý', 'DRUHY', 'exempt');

        try {
            $this->resolver()->resolve($this->items(), $this->date(), false);
            $this->fail('Nejednoznačné režimy neplátce měly být odmítnuty.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('více daňových režimů', $exception->errors()['items'][0]);
        }
    }

    public function test_unusable_default_falls_back_only_to_single_valid_candidate(): void
    {
        $this->activate(BusinessConnection::Business1);

        foreach (['inactive', 'archived', 'expired'] as $state) {
            VatRateDefault::query()->delete();
            VatRate::query()->delete();

            $default = $this->rate('Nepoužitelný default', 'DEFAULT-'.$state, 'out_of_scope', match ($state) {
                'inactive' => ['is_active' => false],
                'archived' => ['archived_at' => now()],
                'expired' => ['valid_to' => '2026-07-31'],
            });
            $fallback = $this->rate('Platný fallback', 'FALLBACK-'.$state, 'exempt');
            $this->setDefault($default);

            $resolved = $this->resolver()->resolve($this->items(), $this->date(), false);

            $this->assertSame($fallback->uuid, $resolved['items'][0]['vat_rate_uuid'], $state);
        }
    }

    public function test_resolution_is_isolated_by_active_business_database(): void
    {
        $this->activate(BusinessConnection::Business1);
        $first = $this->rate('První subjekt', 'S1', 'out_of_scope');
        $firstResolved = $this->resolver()->resolve($this->items(), $this->date(), false);

        $this->activate(BusinessConnection::Business2);
        $second = $this->rate('Druhý subjekt', 'S2', 'exempt');
        $secondResolved = $this->resolver()->resolve($this->items(), $this->date(), false);

        $this->assertSame($first->uuid, $firstResolved['items'][0]['vat_rate_uuid']);
        $this->assertSame($second->uuid, $secondResolved['items'][0]['vat_rate_uuid']);
        $this->assertNotSame($first->uuid, $secondResolved['items'][0]['vat_rate_uuid']);
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

    private function setDefault(VatRate $rate): void
    {
        $default = new VatRateDefault;
        $default->forceFill(['context' => 'sales', 'vat_rate_id' => $rate->id])->save();
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
