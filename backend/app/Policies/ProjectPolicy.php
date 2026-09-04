<?php

namespace App\Policies;

use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Project\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDoAny([
            PermissionCode::ProjectViewAll,
            PermissionCode::ProjectViewAssigned,
            PermissionCode::ProjectViewOwnReferrals,
        ]);
    }

    public function view(User $user, Project $project): bool
    {
        if ($user->canDo(PermissionCode::ProjectViewAll)) {
            return true;
        }

        // A PM sees the projects assigned to them, and nothing else.
        if ($user->canDo(PermissionCode::ProjectViewAssigned)
            && $project->project_manager_user_id === $user->id) {
            return true;
        }

        // A referral agent sees high-level progress for projects arising from
        // their own referrals; the resource strips the detail.
        if ($user->canDo(PermissionCode::ProjectViewOwnReferrals)) {
            $agentId = $user->agentProfile?->id;

            return $agentId !== null
                && $project->opportunity !== null
                && $project->opportunity->referral_agent_id === $agentId;
        }

        return false;
    }

    /**
     * Delivery detail: tasks, timeline, handover checklist and the handover
     * brief.
     *
     * Separate from view() on purpose. A referral agent may see that a project
     * exists and roughly where it is, because that is the "high-level project
     * progress" USER_ROLES_PERMISSION.md allows them. It does not follow that
     * they may read internal tasks, internal notes, or who is doing what —
     * the same document says an agent cannot view internal tasks or notes.
     */
    public function viewInternals(User $user, Project $project): bool
    {
        if ($user->canDo(PermissionCode::ProjectViewAll)) {
            return true;
        }

        return $user->canDo(PermissionCode::ProjectViewAssigned)
            && $project->project_manager_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->canDo(PermissionCode::ProjectCreate);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->canDo(PermissionCode::ProjectUpdate) && $this->view($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->canDo(PermissionCode::ProjectDelete);
    }

    public function assignManager(User $user, Project $project): bool
    {
        return $user->canDo(PermissionCode::ProjectAssignManager);
    }

    public function updateStatus(User $user, Project $project): bool
    {
        return $user->canDo(PermissionCode::ProjectUpdateStatus) && $this->view($user, $project);
    }

    public function manageHandover(User $user, Project $project): bool
    {
        return $user->canDo(PermissionCode::ProjectManageHandover) && $this->view($user, $project);
    }

    /** Contract value is withheld from referral agents. */
    public function viewFinancials(User $user, Project $project): bool
    {
        return $user->canDo(PermissionCode::ProjectViewFinancials) && $this->view($user, $project);
    }
}
