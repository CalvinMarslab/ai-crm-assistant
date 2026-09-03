<?php

namespace App\Policies;

use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Task\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDoAny([PermissionCode::TaskViewAll, PermissionCode::TaskViewOwn]);
    }

    public function view(User $user, Task $task): bool
    {
        if ($user->canDo(PermissionCode::TaskViewAll)) {
            return true;
        }

        return $user->canDo(PermissionCode::TaskViewOwn)
            && in_array($user->id, [$task->assigned_user_id, $task->created_by_user_id], true);
    }

    public function create(User $user): bool
    {
        return $user->canDo(PermissionCode::TaskManage);
    }

    public function update(User $user, Task $task): bool
    {
        return $user->canDo(PermissionCode::TaskManage) && $this->view($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->canDo(PermissionCode::TaskManage) && $this->view($user, $task);
    }
}
