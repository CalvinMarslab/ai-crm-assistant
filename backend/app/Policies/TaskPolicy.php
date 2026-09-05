<?php

namespace App\Policies;

use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Services\TaskVisibility;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDoAny([PermissionCode::TaskViewAll, PermissionCode::TaskViewOwn]);
    }

    public function __construct(private readonly TaskVisibility $visibility) {}

    /**
     * Delegated so the per-record decision and the listing query cannot drift
     * apart. A task attached to a record follows that record's access, which
     * is what stops a former project manager working tasks through their
     * created_by trail after the project moves on.
     */
    public function view(User $user, Task $task): bool
    {
        return $this->visibility->decide($user, $task);
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
