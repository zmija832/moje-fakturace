<?php

namespace App\Policies\Business;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessRole;
use App\Models\User;

abstract class BusinessPolicy
{
    public function __construct(private readonly ActiveBusinessContext $context) {}

    protected function canView(User $user): bool
    {
        return $this->accessRole($user) !== null;
    }

    protected function canManage(User $user): bool
    {
        return BusinessRole::isAdministrator($this->accessRole($user));
    }

    private function accessRole(User $user): ?string
    {
        $businessId = $this->context->id();

        if ($businessId === null) {
            return null;
        }

        $role = $user->businesses()
            ->whereKey($businessId)
            ->where('businesses.is_active', true)
            ->value('user_business_access.role');

        return is_string($role) ? $role : null;
    }
}
