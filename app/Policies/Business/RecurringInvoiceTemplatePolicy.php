<?php

namespace App\Policies\Business;

use App\Models\Business\RecurringInvoiceTemplate;
use App\Models\User;

class RecurringInvoiceTemplatePolicy extends BusinessPolicy
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

    public function update(User $user, ?RecurringInvoiceTemplate $template = null): bool
    {
        return $this->canManage($user);
    }

    public function run(User $user): bool
    {
        return $this->canManage($user);
    }
}
