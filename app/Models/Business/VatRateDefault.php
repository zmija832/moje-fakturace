<?php

namespace App\Models\Business;

use App\Enums\VatRateDefaultContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VatRateDefault extends BusinessModel
{
    public $incrementing = false;

    protected $primaryKey = 'context';

    protected $keyType = 'string';

    public function rate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class, 'vat_rate_id');
    }

    protected function casts(): array
    {
        return ['context' => VatRateDefaultContext::class];
    }
}
