<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'currency',
    'bank_account_id',
])]
class BankAccountDefault extends BusinessModel
{
    public $incrementing = false;

    protected $primaryKey = 'currency';

    protected $keyType = 'string';

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
