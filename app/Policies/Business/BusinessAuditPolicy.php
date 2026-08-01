<?php

namespace App\Policies\Business;

use App\Models\User;

class BusinessAuditPolicy extends BusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user): bool
    {
        return $this->canView($user);
    }
}
