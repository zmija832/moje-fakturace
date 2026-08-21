<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\InvoiceDecimal;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\VatTaxType;
use App\Models\Business\CompanySetting;
use App\Models\Business\InvoiceCatalogItem;
use App\Models\Business\VatRate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceCatalogService
{
    public function __construct(
        private readonly BusinessConnectionResolver $resolver,
        private readonly BusinessAuditSanitizer $sanitizer,
        private readonly BusinessAuditWriter $audit,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $this->resolver->resolve();
        $query = InvoiceCatalogItem::query()->with('vatRate');
        if (($filters['status'] ?? 'active') === 'inactive') {
            $query->where('is_active', false);
        } elseif (($filters['status'] ?? 'active') !== 'all') {
            $query->where('is_active', true);
        }
        if (($search = trim((string) ($filters['q'] ?? ''))) !== '') {
            $query->where('name', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
        }

        return $query->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();
    }

    public function find(string $uuid): InvoiceCatalogItem
    {
        $this->resolver->resolve();

        return InvoiceCatalogItem::query()->with('vatRate')->where('uuid', $uuid)->firstOrFail();
    }

    public function create(array $input): InvoiceCatalogItem
    {
        return $this->save(null, $input);
    }

    public function update(string $uuid, array $input): InvoiceCatalogItem
    {
        return $this->save($uuid, $input);
    }

    public function setActive(string $uuid, bool $active): InvoiceCatalogItem
    {
        $connection = $this->resolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid, $active): InvoiceCatalogItem {
            $item = InvoiceCatalogItem::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $before = $this->sanitizer->snapshot(BusinessAuditableType::InvoiceCatalogItem, $item);
            $item->is_active = $active;
            $item->save();
            if ($before['is_active'] !== $active) {
                $this->audit->write(
                    $active ? BusinessAuditEvent::InvoiceCatalogItemActivated : BusinessAuditEvent::InvoiceCatalogItemDeactivated,
                    BusinessAuditableType::InvoiceCatalogItem,
                    $item->uuid,
                    $before,
                    $this->sanitizer->snapshot(BusinessAuditableType::InvoiceCatalogItem, $item),
                    ['is_active'],
                );
            }

            return $item->refresh();
        }, 3);
    }

    /** @return Collection<int, InvoiceCatalogItem> */
    public function search(string $query, string $currency, int $limit = 15): Collection
    {
        $this->resolver->resolve();
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim(mb_substr($query, 0, 100)));
        $payer = (bool) CompanySetting::query()
            ->where('singleton_key', CompanySetting::SINGLETON_KEY)
            ->value('is_vat_payer');

        return InvoiceCatalogItem::query()->with('vatRate')
            ->where('is_active', true)->where('currency', $currency)
            ->when(
                $payer,
                fn ($builder) => $builder->where(fn ($vat) => $vat
                    ->whereNull('vat_rate_uuid')
                    ->orWhereHas('vatRate', fn ($rate) => $rate
                        ->where('is_active', true)
                        ->whereNull('archived_at')
                        ->where('tax_type', '!=', VatTaxType::NonPayer->value))),
                fn ($builder) => $builder->whereNull('vat_rate_uuid'),
            )
            ->when($escaped !== '', fn ($builder) => $builder->where('name', 'like', '%'.$escaped.'%'))
            ->orderBy('name')->limit(min(max($limit, 1), 20))->get();
    }

    private function save(?string $uuid, array $input): InvoiceCatalogItem
    {
        $connection = $this->resolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid, $input): InvoiceCatalogItem {
            $item = $uuid === null
                ? new InvoiceCatalogItem
                : InvoiceCatalogItem::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $before = $item->exists ? $this->sanitizer->snapshot(BusinessAuditableType::InvoiceCatalogItem, $item) : null;
            $input['unit_price'] = ($input['unit_price'] ?? null) === null
                ? null
                : InvoiceDecimal::database($input['unit_price']);
            $input['vat_rate_uuid'] = $this->validatedVatRate($input['vat_rate_uuid'] ?? null);
            $item->fill($input);
            $changed = $item->exists ? $this->sanitizer->changedFields(BusinessAuditableType::InvoiceCatalogItem, $item) : [];
            $item->save();
            if (! $item->wasRecentlyCreated && $changed === []) {
                return $item->refresh();
            }
            $after = $this->sanitizer->snapshot(BusinessAuditableType::InvoiceCatalogItem, $item);
            $this->audit->write(
                $item->wasRecentlyCreated ? BusinessAuditEvent::InvoiceCatalogItemCreated : BusinessAuditEvent::InvoiceCatalogItemUpdated,
                BusinessAuditableType::InvoiceCatalogItem,
                $item->uuid,
                $before,
                $after,
                $item->wasRecentlyCreated ? array_keys($after) : $changed,
            );

            return $item->refresh()->load('vatRate');
        }, 3);
    }

    private function validatedVatRate(?string $uuid): ?string
    {
        $payer = (bool) CompanySetting::query()->where('singleton_key', CompanySetting::SINGLETON_KEY)->value('is_vat_payer');
        if (! $payer) {
            if ($uuid !== null) {
                throw ValidationException::withMessages(['vat_rate_uuid' => 'Neplátce DPH nesmí mít u katalogové položky sazbu DPH.']);
            }

            return null;
        }
        if ($uuid === null) {
            return null;
        }
        $valid = VatRate::query()->where('uuid', $uuid)->where('is_active', true)->whereNull('archived_at')
            ->where('tax_type', '!=', VatTaxType::NonPayer->value)->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['vat_rate_uuid' => 'Vybraná sazba DPH není dostupná.']);
        }

        return $uuid;
    }
}
