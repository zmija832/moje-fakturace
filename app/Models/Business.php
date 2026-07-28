<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'uuid',
    'display_name',
    'registration_number',
    'short_label',
    'visual_identifier',
    'connection_name',
    'is_active',
    'sort_order',
])]
class Business extends CentralModel
{
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_business_access')
            ->withPivot('role')
            ->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
