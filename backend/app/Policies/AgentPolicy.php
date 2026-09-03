<?php

namespace App\Policies;

use App\Domain\Agent\Models\Agent;
use App\Domain\Identity\Enums\PermissionCode;
use App\Models\User;

class AgentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDoAny([PermissionCode::AgentViewAll, PermissionCode::AgentViewOwn]);
    }

    public function view(User $user, Agent $agent): bool
    {
        if ($user->canDo(PermissionCode::AgentViewAll)) {
            return true;
        }

        return $user->canDo(PermissionCode::AgentViewOwn) && $agent->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->canDo(PermissionCode::AgentManage);
    }

    public function update(User $user, Agent $agent): bool
    {
        return $user->canDo(PermissionCode::AgentManage);
    }

    public function delete(User $user, Agent $agent): bool
    {
        return $user->canDo(PermissionCode::AgentManage);
    }
}
