<?php

namespace App\Policies;

use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Models\User;

class OpportunityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDoAny([
            PermissionCode::OpportunityViewAll,
            PermissionCode::OpportunityViewOwn,
            PermissionCode::OpportunityViewOwnReferrals,
        ]);
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        if ($user->canDo(PermissionCode::OpportunityViewAll)) {
            return true;
        }

        // A referral agent sees only what their own agent profile introduced.
        if ($user->canDo(PermissionCode::OpportunityViewOwnReferrals)) {
            $agentId = $user->agentProfile?->id;

            if ($agentId !== null && $opportunity->referral_agent_id === $agentId) {
                return true;
            }
        }

        return $user->canDo(PermissionCode::OpportunityViewOwn)
            && $opportunity->owner_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->canDo(PermissionCode::OpportunityCreate);
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return $user->canDo(PermissionCode::OpportunityUpdate) && $this->view($user, $opportunity);
    }

    public function delete(User $user, Opportunity $opportunity): bool
    {
        return $user->canDo(PermissionCode::OpportunityDelete);
    }

    public function changeStage(User $user, Opportunity $opportunity): bool
    {
        return $user->canDo(PermissionCode::OpportunityChangeStage) && $this->view($user, $opportunity);
    }

    public function assignOwner(User $user, Opportunity $opportunity): bool
    {
        return $user->canDo(PermissionCode::OpportunityAssignOwner);
    }

    public function assignAgent(User $user, Opportunity $opportunity): bool
    {
        return $user->canDo(PermissionCode::OpportunityAssignAgent);
    }

    /** Quotation margin and value detail are withheld from referral agents. */
    public function viewFinancials(User $user, Opportunity $opportunity): bool
    {
        return $user->canDo(PermissionCode::OpportunityViewFinancials) && $this->view($user, $opportunity);
    }

    public function viewInternalNotes(User $user, Opportunity $opportunity): bool
    {
        return $user->canDo(PermissionCode::OpportunityViewInternalNotes) && $this->view($user, $opportunity);
    }
}
