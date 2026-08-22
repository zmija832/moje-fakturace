<?php

namespace App\Models\Business;

use App\Models\Concerns\HasServerGeneratedUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['is_enabled'])]
class FioBankAccountSetting extends BusinessModel
{
    use HasServerGeneratedUuid;

    protected $hidden = ['encrypted_token', 'sync_claim_token'];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    protected function casts(): array
    {
        return [
            'encrypted_token' => 'encrypted',
            'is_enabled' => 'boolean',
            'sync_claimed_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'last_successful_sync_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
        ];
    }
}
