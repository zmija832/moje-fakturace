<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Vat\Exceptions\VatRateUnavailable;
use App\Domain\Vat\VatPercentage;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\InvoiceStatus;
use App\Enums\VatRateDefaultContext;
use App\Enums\VatTaxType;
use App\Models\Business\CompanySetting;
use App\Models\Business\InvoiceVatSnapshot;
use App\Models\Business\VatRate;
use App\Models\Business\VatRateDefault;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class VatRateService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, VatRate> */
    public function search(array $filters): LengthAwarePaginator
    {
        $query = VatRate::query()->with('defaultAssignment');
        $status = $filters['status'] ?? 'current';

        match ($status) {
            'active' => $query->whereNull('archived_at')->where('is_active', true),
            'inactive' => $query->whereNull('archived_at')->where('is_active', false),
            'archived' => $query->whereNotNull('archived_at'),
            'all' => null,
            default => $query->whereNull('archived_at'),
        };

        if (is_string($filters['tax_type'] ?? null) && VatTaxType::tryFrom($filters['tax_type'])) {
            $query->where('tax_type', $filters['tax_type']);
        }

        if (is_string($filters['valid_on'] ?? null) && $filters['valid_on'] !== '') {
            $query->whereDate('valid_from', '<=', $filters['valid_on'])
                ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $filters['valid_on']));
        }

        $sort = in_array($filters['sort'] ?? null, ['sort_order', 'name', 'code', 'tax_type', 'valid_from'], true)
            ? $filters['sort']
            : 'sort_order';
        $direction = ($filters['direction'] ?? null) === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sort, $direction)
            ->orderBy('name')
            ->orderBy('valid_from')
            ->paginate(25)
            ->withQueryString();
    }

    public function newForForm(): VatRate
    {
        return new VatRate([
            'tax_type' => VatTaxType::Standard,
            'valid_from' => today(),
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function find(string $uuid): VatRate
    {
        return VatRate::query()->with('defaultAssignment')->where('uuid', $uuid)->firstOrFail();
    }

    public function findForEdit(string $uuid): VatRate
    {
        return VatRate::query()->with('defaultAssignment')->where('uuid', $uuid)->whereNull('archived_at')->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): VatRate
    {
        $attributes = $this->normalize($attributes);

        return $this->withCodeLock($attributes['code'], function () use ($attributes): VatRate {
            $connection = $this->connectionName();

            return DB::connection($connection)->transaction(function () use ($attributes): VatRate {
                $this->ensureNoOverlap($attributes['code'], $attributes['valid_from'], $attributes['valid_to']);
                $rate = new VatRate;
                $rate->fill($attributes);
                $rate->save();
                $snapshot = $this->auditSanitizer->snapshot(BusinessAuditableType::VatRate, $rate);
                $this->auditWriter->write(
                    BusinessAuditEvent::VatRateCreated,
                    BusinessAuditableType::VatRate,
                    $rate->uuid,
                    null,
                    $snapshot,
                    array_keys($snapshot),
                );

                return $rate->refresh()->load('defaultAssignment');
            }, 3);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(string $uuid, array $attributes): VatRate
    {
        $attributes = $this->normalize($attributes);
        $existing = $this->findForEdit($uuid);
        $codes = array_values(array_unique([$existing->code, $attributes['code']]));
        sort($codes);

        return $this->withCodeLocks($codes, function () use ($uuid, $attributes): VatRate {
            $connection = $this->connectionName();

            return DB::connection($connection)->transaction(function () use ($uuid, $attributes): VatRate {
                $rate = $this->lockedRate($uuid, true);

                if ($this->hasIssuedDocumentUsage($rate)) {
                    throw ValidationException::withMessages([
                        'rate' => 'Historická pole sazby použité na vystaveném dokladu nelze změnit.',
                    ]);
                }

                $this->ensureNoOverlap($attributes['code'], $attributes['valid_from'], $attributes['valid_to'], $rate->id);
                $oldValues = $this->auditSanitizer->snapshot(BusinessAuditableType::VatRate, $rate);
                $rate->fill($attributes);
                $changedFields = $this->auditSanitizer->changedFields(BusinessAuditableType::VatRate, $rate);
                $rate->save();

                if ($changedFields !== []) {
                    $this->auditWriter->write(
                        BusinessAuditEvent::VatRateUpdated,
                        BusinessAuditableType::VatRate,
                        $rate->uuid,
                        $oldValues,
                        $this->auditSanitizer->snapshot(BusinessAuditableType::VatRate, $rate),
                        $changedFields,
                    );
                }

                if (! $rate->is_active) {
                    $this->removeDefault($rate, 'vat_rate_updated_inactive');
                }

                return $rate->refresh()->load('defaultAssignment');
            }, 3);
        });
    }

    public function activate(string $uuid): VatRate
    {
        return $this->changeActiveState($uuid, true);
    }

    public function deactivate(string $uuid): VatRate
    {
        return $this->changeActiveState($uuid, false);
    }

    public function archive(string $uuid): VatRate
    {
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid): VatRate {
            $rate = $this->lockedRate($uuid, true);
            $wasActive = (bool) $rate->is_active;
            $rate->forceFill(['is_active' => false, 'archived_at' => now()])->save();
            $this->auditWriter->write(
                BusinessAuditEvent::VatRateArchived,
                BusinessAuditableType::VatRate,
                $rate->uuid,
                ['is_active' => $wasActive, 'is_archived' => false],
                ['is_active' => false, 'is_archived' => true],
                ['is_active', 'archived_at'],
            );
            $this->removeDefault($rate, 'vat_rate_archived');

            return $rate->refresh()->load('defaultAssignment');
        }, 3);
    }

    public function setDefault(string $uuid, VatRateDefaultContext $context = VatRateDefaultContext::Sales): VatRate
    {
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid, $context): VatRate {
            $rate = $this->lockedRate($uuid);

            if (! $rate->is_active || $rate->isArchived()) {
                throw ValidationException::withMessages(['rate' => 'Výchozí může být pouze aktivní a nearchivovaná sazba.']);
            }

            if (! $this->isVatPayer() && ! $rate->tax_type->allowedAsNonPayerDefault()) {
                throw ValidationException::withMessages([
                    'rate' => 'Neplátce DPH může jako výchozí použít pouze režim mimo předmět DPH nebo osvobozené plnění.',
                ]);
            }

            $assignment = VatRateDefault::query()->where('context', $context->value)->lockForUpdate()->first();
            $oldRateUuid = $assignment ? VatRate::query()->whereKey($assignment->vat_rate_id)->value('uuid') : null;

            $assignment ??= new VatRateDefault;
            $assignment->forceFill(['context' => $context->value, 'vat_rate_id' => $rate->id]);
            $assignment->save();

            if ($oldRateUuid !== $rate->uuid) {
                $this->auditWriter->write(
                    BusinessAuditEvent::VatRateDefaultChanged,
                    BusinessAuditableType::VatRateDefault,
                    $rate->uuid,
                    $oldRateUuid ? ['context' => $context->value, 'vat_rate_uuid' => $oldRateUuid] : null,
                    ['context' => $context->value, 'vat_rate_uuid' => $rate->uuid],
                    ['vat_rate_uuid'],
                    BusinessAuditableType::VatRate,
                    $rate->uuid,
                );
            }

            return $rate->refresh()->load('defaultAssignment');
        }, 3);
    }

    public function removeDefaultForContext(VatRateDefaultContext $context): void
    {
        $connection = $this->connectionName();
        DB::connection($connection)->transaction(function () use ($context): void {
            $assignment = VatRateDefault::query()->where('context', $context->value)->lockForUpdate()->first();

            if ($assignment === null) {
                return;
            }

            $rate = VatRate::query()->whereKey($assignment->vat_rate_id)->firstOrFail();
            $this->removeDefault($rate, 'default_removed_by_administrator');
        }, 3);
    }

    public function resolveForDate(string $uuid, CarbonImmutable $taxDate): VatRate
    {
        $date = $taxDate->toDateString();
        $rate = VatRate::query()
            ->where('uuid', $uuid)
            ->whereNull('archived_at')
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->first();

        if ($rate === null) {
            throw VatRateUnavailable::forDate();
        }

        return $rate;
    }

    public function resolveDefaultForDate(VatRateDefaultContext $context, CarbonImmutable $taxDate): VatRate
    {
        $assignment = VatRateDefault::query()->where('context', $context->value)->first();

        if ($assignment === null) {
            throw VatRateUnavailable::missingDefault();
        }

        $rate = VatRate::query()->whereKey($assignment->vat_rate_id)->first();

        if ($rate === null || (! $this->isVatPayer() && ! $rate->tax_type->allowedAsNonPayerDefault())) {
            throw VatRateUnavailable::missingDefault();
        }

        return $this->resolveForDate($rate->uuid, $taxDate);
    }

    public function isVatPayer(): bool
    {
        return (bool) CompanySetting::query()
            ->where('singleton_key', CompanySetting::SINGLETON_KEY)
            ->value('is_vat_payer');
    }

    public function hasIssuedDocumentUsage(VatRate $rate): bool
    {
        return InvoiceVatSnapshot::query()
            ->join('invoice_revisions', 'invoice_revisions.id', '=', 'invoice_vat_snapshots.invoice_revision_id')
            ->join('invoices', 'invoices.id', '=', 'invoice_revisions.invoice_id')
            ->where('invoice_vat_snapshots.source_vat_rate_uuid', $rate->uuid)
            ->where('invoices.status', InvoiceStatus::Issued->value)
            ->whereColumn('invoice_vat_snapshots.invoice_revision_id', 'invoices.issued_revision_id')
            ->exists();
    }

    private function changeActiveState(string $uuid, bool $active): VatRate
    {
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid, $active): VatRate {
            $rate = $this->lockedRate($uuid);

            if ($rate->isArchived()) {
                throw ValidationException::withMessages(['rate' => 'Archivovanou sazbu nelze aktivovat ani deaktivovat.']);
            }

            $old = (bool) $rate->is_active;
            $rate->is_active = $active;
            $rate->save();

            if ($old !== $active) {
                $this->auditWriter->write(
                    $active ? BusinessAuditEvent::VatRateActivated : BusinessAuditEvent::VatRateDeactivated,
                    BusinessAuditableType::VatRate,
                    $rate->uuid,
                    ['is_active' => $old],
                    ['is_active' => $active],
                    ['is_active'],
                );
            }

            if (! $active) {
                $this->removeDefault($rate, 'vat_rate_deactivated');
            }

            return $rate->refresh()->load('defaultAssignment');
        }, 3);
    }

    private function removeDefault(VatRate $rate, string $reason): void
    {
        $assignment = VatRateDefault::query()->where('vat_rate_id', $rate->id)->lockForUpdate()->first();

        if ($assignment === null) {
            return;
        }

        $context = $assignment->context->value;
        $assignment->delete();
        $this->auditWriter->write(
            BusinessAuditEvent::VatRateDefaultRemoved,
            BusinessAuditableType::VatRateDefault,
            $rate->uuid,
            ['context' => $context, 'vat_rate_uuid' => $rate->uuid],
            null,
            ['vat_rate_uuid'],
            BusinessAuditableType::VatRate,
            $rate->uuid,
            ['reason' => $reason],
        );
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function normalize(array $attributes): array
    {
        $attributes['code'] = mb_strtoupper(trim((string) $attributes['code']));
        $type = VatTaxType::from((string) $attributes['tax_type']);

        if (
            isset($attributes['valid_to'])
            && $attributes['valid_to'] !== ''
            && (string) $attributes['valid_to'] < (string) $attributes['valid_from']
        ) {
            throw ValidationException::withMessages([
                'valid_to' => 'Konec platnosti nesmí být před začátkem platnosti.',
            ]);
        }

        if ($type->requiresPercentage()) {
            if (! isset($attributes['percentage']) || $attributes['percentage'] === '') {
                throw ValidationException::withMessages(['percentage' => 'Pro tento režim je sazba povinná.']);
            }

            try {
                $percentage = VatPercentage::from(is_int($attributes['percentage']) ? $attributes['percentage'] : (string) $attributes['percentage']);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['percentage' => $exception->getMessage()]);
            }

            if ($type === VatTaxType::Zero && ! $percentage->isZero()) {
                throw ValidationException::withMessages(['percentage' => 'Nulová sazba musí být přesně 0 %.']);
            }

            $attributes['percentage'] = $percentage->value;
        } else {
            if (($attributes['percentage'] ?? null) !== null && $attributes['percentage'] !== '') {
                throw ValidationException::withMessages(['percentage' => 'Tento daňový režim nemá procentní sazbu.']);
            }

            $attributes['percentage'] = null;
        }

        return $attributes;
    }

    private function ensureNoOverlap(string $code, string $from, ?string $to, ?int $ignoreId = null): void
    {
        $query = VatRate::query()
            ->where('code', $code)
            ->whereNull('archived_at')
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereDate('valid_from', '<=', $to ?? '9999-12-31')
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from))
            ->lockForUpdate();

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'valid_from' => 'Platnost sazby se překrývá s jiným nearchivovaným obdobím stejného kódu.',
            ]);
        }
    }

    private function lockedRate(string $uuid, bool $editableOnly = false): VatRate
    {
        return VatRate::query()
            ->where('uuid', $uuid)
            ->when($editableOnly, fn ($query) => $query->whereNull('archived_at'))
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @template T @param callable(): T $callback @return T */
    private function withCodeLock(string $code, callable $callback): mixed
    {
        return $this->withCodeLocks([$code], $callback);
    }

    /** @template T @param list<string> $codes @param callable(): T $callback @return T */
    private function withCodeLocks(array $codes, callable $callback): mixed
    {
        $connection = $this->connectionName();
        $database = (string) config("database.connections.{$connection}.database");
        $locks = [];

        try {
            foreach ($codes as $code) {
                $lock = 'vat:'.substr(hash('sha256', $database.'|'.mb_strtoupper($code)), 0, 60);
                $result = DB::connection($connection)->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lock]);

                if ((int) ($result->acquired ?? 0) !== 1) {
                    throw new RuntimeException('Nepodařilo se bezpečně zamknout časová období sazby DPH.');
                }

                $locks[] = $lock;
            }

            return $callback();
        } finally {
            foreach (array_reverse($locks) as $lock) {
                DB::connection($connection)->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
        }
    }

    private function connectionName(): string
    {
        return $this->connectionResolver->resolve()->connectionName();
    }
}
