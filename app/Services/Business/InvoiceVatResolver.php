<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\VatRateDefaultContext;
use App\Enums\VatTaxType;
use App\Models\Business\CompanySetting;
use App\Models\Business\VatRate;
use App\Models\Business\VatRateDefault;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class InvoiceVatResolver
{
    public function __construct(private readonly BusinessConnectionResolver $connectionResolver) {}

    public function isVatPayer(): bool
    {
        $this->connectionResolver->resolve();

        return (bool) CompanySetting::query()
            ->where('singleton_key', CompanySetting::SINGLETON_KEY)
            ->value('is_vat_payer');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{items: list<array<string, mixed>>, rates: array<string, VatRate>}
     */
    public function resolve(array $items, CarbonImmutable $taxDate, bool $isVatPayer, bool $lockForUpdate = false): array
    {
        $this->connectionResolver->resolve();

        if (! $isVatPayer) {
            $rate = $this->resolveNonPayerRate($taxDate, $lockForUpdate);
            $items = array_map(static function (array $item) use ($rate): array {
                $item['vat_rate_uuid'] = $rate->uuid;

                return $item;
            }, $items);

            return ['items' => $items, 'rates' => [$rate->uuid => $rate]];
        }

        $rates = [];

        foreach ($items as $index => $item) {
            $uuid = (string) ($item['vat_rate_uuid'] ?? '');

            if ($uuid === '') {
                throw ValidationException::withMessages([
                    "items.{$index}.vat_rate_uuid" => 'Vyberte sazbu DPH.',
                ]);
            }
            if (isset($rates[$uuid])) {
                continue;
            }

            $rate = $this->validRateQuery($taxDate)->where('uuid', $uuid);
            if ($lockForUpdate) {
                $rate->lockForUpdate();
            }
            $resolved = $rate->first();

            if ($resolved === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.vat_rate_uuid" => 'Vybraná sazba DPH není pro zadané DUZP dostupná.',
                ]);
            }
            $rates[$uuid] = $resolved;
        }

        return ['items' => $items, 'rates' => $rates];
    }

    private function resolveNonPayerRate(CarbonImmutable $taxDate, bool $lockForUpdate): VatRate
    {
        $assignment = VatRateDefault::query()
            ->where('context', VatRateDefaultContext::Sales->value);
        if ($lockForUpdate) {
            $assignment->lockForUpdate();
        }
        $default = $assignment->first();

        if ($default !== null) {
            $query = $this->nonPayerRateQuery($taxDate)->whereKey($default->vat_rate_id);
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }
            $rate = $query->first();

            if ($rate !== null) {
                return $rate;
            }
        }

        $query = $this->nonPayerRateQuery($taxDate)->orderBy('id')->limit(2);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $candidates = $query->get();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }
        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Pro neplátce není k zadanému DUZP dostupný daňový režim mimo předmět DPH nebo osvobozené plnění.',
            ]);
        }

        throw ValidationException::withMessages([
            'items' => 'Pro neplátce je k zadanému DUZP dostupných více daňových režimů. Nastavte jednoznačný výchozí režim pro prodej.',
        ]);
    }

    /** @return Builder<VatRate> */
    private function nonPayerRateQuery(CarbonImmutable $taxDate): Builder
    {
        $types = array_values(array_map(
            static fn (VatTaxType $type): string => $type->value,
            array_filter(VatTaxType::cases(), static fn (VatTaxType $type): bool => $type->allowedAsNonPayerDefault()),
        ));

        return $this->validRateQuery($taxDate)->whereIn('tax_type', $types);
    }

    /** @return Builder<VatRate> */
    private function validRateQuery(CarbonImmutable $taxDate): Builder
    {
        $date = $taxDate->toDateString();

        return VatRate::query()
            ->whereNull('archived_at')
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }
}
