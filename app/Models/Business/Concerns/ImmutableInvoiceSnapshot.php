<?php

namespace App\Models\Business\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

trait ImmutableInvoiceSnapshot
{
    protected static function bootImmutableInvoiceSnapshot(): void
    {
        static::updating(fn (Model $model) => throw new LogicException('Historický záznam revize faktury je neměnný.'));
        static::deleting(fn (Model $model) => throw new LogicException('Historický záznam revize faktury nelze smazat.'));
    }
}
