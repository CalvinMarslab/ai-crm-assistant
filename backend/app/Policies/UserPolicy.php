<?php

namespace App\Policies;

use App\Domain\Identity\Enums\PermissionCode;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo(PermissionCode::UserViewAll);
    }

    public function view(User $user, User $model): bool
    {
        return $user->canDo(PermissionCode::UserViewAll) || $user->id === $model->id;
    }

    public function create(User $user): bool
    {
        return $user->canDo(PermissionCode::UserManage);
    }

    public function update(User $user, User $model): bool
    {
        return $user->canDo(PermissionCode::UserManage);
    }
}
