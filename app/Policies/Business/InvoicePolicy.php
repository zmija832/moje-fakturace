<?php

namespace App\Policies\Business;

use App\Models\User;

class InvoicePolicy extends BusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function updateAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user): bool
    {
        return $this->canManage($user);
    }

    public function issue(User $user): bool
    {
        return $this->canManage($user);
    }
}
