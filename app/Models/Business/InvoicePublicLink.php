<?php

namespace App\Models\Business;

use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([])]
class InvoicePublicLink extends BusinessModel
{
    use HasServerGeneratedUuid;

    protected static function booted(): void
    {
        static::updating(function (self $link): void {
            $allowed = ['revoked_at', 'revoked_by_actor', 'first_viewed_at', 'last_viewed_at', 'updated_at'];
            if ($link->getOriginal('revoked_at') !== null
                || ($link->getOriginal('first_viewed_at') !== null && $link->isDirty('first_viewed_at'))
                || array_diff(array_keys($link->getDirty()), $allowed) !== []) {
                throw new LogicException('Veřejný odkaz faktury lze pouze odvolat.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Historii veřejných odkazů nelze smazat.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    protected function casts(): array
    {
        return [
            'token_ciphertext' => 'encrypted',
            'first_viewed_at' => 'immutable_datetime',
            'last_viewed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
