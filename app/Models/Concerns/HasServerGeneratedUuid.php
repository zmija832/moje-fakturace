<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasServerGeneratedUuid
{
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function bootHasServerGeneratedUuid(): void
    {
        static::creating(function (Model $model): void {
            $model->setAttribute('uuid', $model->getAttribute('uuid') ?? (string) Str::uuid());
        });
    }
}
