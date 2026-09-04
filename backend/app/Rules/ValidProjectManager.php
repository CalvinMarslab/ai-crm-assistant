<?php

namespace App\Rules;

use App\Domain\Identity\Enums\PermissionCode;
use App\Models\User;
use App\Support\OrganizationContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A project manager must actually be able to manage projects.
 *
 * Assigning somebody who cannot produces a project nobody can work on, plus a
 * notification telling them to do something they have no access to. The check
 * is on the permission rather than the role name, so an organization that
 * later defines its own delivery role does not need this rule changed —
 * consistent with the granular permission design in USER_ROLES_PERMISSION.md.
 *
 * Shared by convert-to-project and assignManager so the two cannot diverge.
 */
class ValidProjectManager implements ValidationRule
{
    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Clearing the manager is always allowed.
        if ($value === null || $value === '') {
            return;
        }

        $user = User::query()
            ->where('uuid', $value)
            ->where('organization_id', OrganizationContext::id())
            ->with('roles.permissions:id,code')
            ->first();

        if ($user === null) {
            $fail('The selected user is not part of this organization.');

            return;
        }

        if ($user->status !== 'active') {
            $fail('The selected user is not active and cannot be assigned as project manager.');

            return;
        }

        if (! $user->canDo(PermissionCode::ProjectViewAssigned)) {
            $fail('The selected user cannot manage projects. Choose a project manager.');
        }
    }
}
