<?php

namespace App\Policies;

use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Service\Models\ServiceSubscription;
use App\Models\User;

class ServiceSubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDoAny([PermissionCode::ServiceView, PermissionCode::ServiceViewServer, PermissionCode::ServiceViewMaintenance]);
    }

    public function view(User $user, ServiceSubscription $service): bool
    {
        return $user->canDo(PermissionCode::ServiceView)
            || ($service->type === 'server' && $user->canDo(PermissionCode::ServiceViewServer))
            || ($service->type === 'maintenance' && $user->canDo(PermissionCode::ServiceViewMaintenance));
    }

    public function create(User $user): bool
    {
        return $user->canDoAny([PermissionCode::ServiceManage, PermissionCode::ServiceManageServer, PermissionCode::ServiceManageMaintenance]);
    }

    public function update(User $user, ServiceSubscription $service): bool
    {
        return $user->canDo(PermissionCode::ServiceManage)
            || ($service->type === 'server' && $user->canDo(PermissionCode::ServiceManageServer))
            || ($service->type === 'maintenance' && $user->canDo(PermissionCode::ServiceManageMaintenance));
    }
}
