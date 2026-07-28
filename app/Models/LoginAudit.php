<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;

#[Guarded([])]
class LoginAudit extends CentralModel
{
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
