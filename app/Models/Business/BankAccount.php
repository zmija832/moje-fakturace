<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'domestic_prefix',
    'domestic_account_number',
    'bank_code',
    'iban',
    'bic',
    'currency',
    'is_active',
    'sort_order',
    'note',
])]
class BankAccount extends BusinessModel
{
    public function defaultAssignment(): HasOne
    {
        return $this->hasOne(BankAccountDefault::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function domesticDisplay(): ?string
    {
        if (! $this->domestic_account_number) {
            return null;
        }

        $prefix = $this->domestic_prefix ? $this->domestic_prefix.'-' : '';
        $bankCode = $this->bank_code ? '/'.$this->bank_code : '';

        return $prefix.$this->domestic_account_number.$bankCode;
    }

    protected static function booted(): void
    {
        static::creating(function (BankAccount $account): void {
            $account->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
