<?php

namespace App\Policies\Business;

use App\Models\User;

class InvoiceEmailSettingPolicy extends BusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function updateAny(User $user): bool
    {
        return $this->canManage($user);
    }
}
