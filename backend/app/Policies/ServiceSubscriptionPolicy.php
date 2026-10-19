<?php

namespace App\Policies;

use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Service\Models\ServiceSubscription;
use App\Models\User;

class ServiceSubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo(PermissionCode::ServiceView);
    }

    public function view(User $user, ServiceSubscription $service): bool
    {
        return $user->canDo(PermissionCode::ServiceView);
    }

    public function create(User $user): bool
    {
        return $user->canDo(PermissionCode::ServiceManage);
    }

    public function update(User $user, ServiceSubscription $service): bool
    {
        return $user->canDo(PermissionCode::ServiceManage);
    }
}
