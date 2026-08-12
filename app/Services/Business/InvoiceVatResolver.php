<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\VatTaxType;
use App\Models\Business\CompanySetting;
use App\Models\Business\VatRate;
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
            $errorKey = 'items.'.$index.'.vat_rate_uuid';

            if ($uuid === '') {
                throw ValidationException::withMessages([
                    $errorKey => 'Vyberte sazbu DPH.',
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
                    $errorKey => 'Vybraná sazba DPH není pro zadané DUZP dostupná.',
                ]);
            }
            if ($resolved->tax_type === VatTaxType::NonPayer) {
                throw ValidationException::withMessages([
                    $errorKey => 'Systémový režim neplátce DPH nemůže použít plátce DPH.',
                ]);
            }
            $rates[$uuid] = $resolved;
        }

        return ['items' => $items, 'rates' => $rates];
    }

    private function resolveNonPayerRate(CarbonImmutable $taxDate, bool $lockForUpdate): VatRate
    {
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
                'items' => 'Pro neplátce DPH není k zadanému DUZP dostupný systémový režim Neplátce DPH.',
            ]);
        }

        throw ValidationException::withMessages([
            'items' => 'Pro neplátce DPH je k zadanému DUZP dostupných více systémových režimů Neplátce DPH.',
        ]);
    }

    /** @return Builder<VatRate> */
    private function nonPayerRateQuery(CarbonImmutable $taxDate): Builder
    {
        return $this->validRateQuery($taxDate)->where('tax_type', VatTaxType::NonPayer->value);
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
