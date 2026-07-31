<?php

namespace App\Services\Business;

use App\Domain\BankAccounts\BankAccountNormalizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Models\Business\BankAccount;
use App\Models\Business\BankAccountDefault;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankAccountService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly CompanySettingsService $companySettingsService,
    ) {}

    /**
     * @return Collection<int, BankAccount>
     */
    public function all(): Collection
    {
        return BankAccount::query()
            ->with('defaultAssignment')
            ->orderByRaw('archived_at IS NOT NULL')
            ->orderBy('currency')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function newForForm(): BankAccount
    {
        return new BankAccount([
            'currency' => $this->companySettingsService->forForm()->default_currency,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function findForEdit(string $uuid): BankAccount
    {
        return BankAccount::query()
            ->with('defaultAssignment')
            ->where('uuid', $uuid)
            ->whereNull('archived_at')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): BankAccount
    {
        $connection = $this->connectionName();
        $attributes = BankAccountNormalizer::normalize($attributes);

        return DB::connection($connection)->transaction(function () use ($attributes): BankAccount {
            $account = new BankAccount;
            $account->fill($attributes);
            $account->save();

            return $account->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $uuid, array $attributes): BankAccount
    {
        $connection = $this->connectionName();
        $attributes = BankAccountNormalizer::normalize($attributes);

        return DB::connection($connection)->transaction(function () use ($uuid, $attributes): BankAccount {
            $account = $this->lockedAccount($uuid, editableOnly: true);
            $newCurrency = $attributes['currency'] ?? $account->currency;

            if (
                $newCurrency !== $account->currency
                && BankAccountDefault::query()->where('bank_account_id', $account->id)->exists()
            ) {
                throw ValidationException::withMessages([
                    'currency' => 'Měnu výchozího účtu nelze změnit. Nejprve zvolte jiný výchozí účet.',
                ]);
            }

            $account->fill($attributes);
            $account->save();

            if (! $account->is_active) {
                $this->removeDefaultAssignment($account);
            }

            return $account->refresh()->load('defaultAssignment');
        }, 3);
    }

    public function setDefault(string $uuid): BankAccount
    {
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid): BankAccount {
            $account = $this->lockedAccount($uuid);

            if (! $account->is_active || $account->archived_at !== null) {
                throw ValidationException::withMessages([
                    'account' => 'Výchozí může být pouze aktivní a nearchivovaný účet.',
                ]);
            }

            $assignment = BankAccountDefault::query()
                ->where('currency', $account->currency)
                ->lockForUpdate()
                ->first();

            $assignment ??= new BankAccountDefault;
            $assignment->fill([
                'currency' => $account->currency,
                'bank_account_id' => $account->id,
            ]);
            $assignment->save();

            return $account->refresh()->load('defaultAssignment');
        }, 3);
    }

    public function deactivate(string $uuid): BankAccount
    {
        return $this->changeActiveState($uuid, false);
    }

    public function activate(string $uuid): BankAccount
    {
        return $this->changeActiveState($uuid, true);
    }

    public function archive(string $uuid): BankAccount
    {
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid): BankAccount {
            $account = $this->lockedAccount($uuid, editableOnly: true);
            $account->forceFill([
                'is_active' => false,
                'archived_at' => now(),
            ])->save();
            $this->removeDefaultAssignment($account);

            return $account->refresh()->load('defaultAssignment');
        }, 3);
    }

    private function changeActiveState(string $uuid, bool $isActive): BankAccount
    {
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid, $isActive): BankAccount {
            $account = $this->lockedAccount($uuid);

            if ($account->archived_at !== null) {
                throw ValidationException::withMessages([
                    'account' => 'Archivovaný účet nelze aktivovat ani deaktivovat.',
                ]);
            }

            $account->is_active = $isActive;
            $account->save();

            if (! $isActive) {
                $this->removeDefaultAssignment($account);
            }

            return $account->refresh()->load('defaultAssignment');
        }, 3);
    }

    private function lockedAccount(string $uuid, bool $editableOnly = false): BankAccount
    {
        return BankAccount::query()
            ->where('uuid', $uuid)
            ->when($editableOnly, fn ($query) => $query->whereNull('archived_at'))
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function removeDefaultAssignment(BankAccount $account): void
    {
        BankAccountDefault::query()
            ->where('bank_account_id', $account->id)
            ->delete();
    }

    private function connectionName(): string
    {
        return $this->connectionResolver->resolve()->connectionName();
    }
}
