<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['key', 'value', 'value_type', 'is_public'])]
class ApplicationSetting extends CentralModel
{
    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }
}
