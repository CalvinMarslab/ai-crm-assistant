<?php

namespace App\Policies;

use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\PermissionCode;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo(PermissionCode::CompanyViewAll);
    }

    public function view(User $user, Company $company): bool
    {
        return $user->canDo(PermissionCode::CompanyViewAll);
    }

    public function create(User $user): bool
    {
        return $user->canDo(PermissionCode::CompanyManage);
    }

    public function update(User $user, Company $company): bool
    {
        return $user->canDo(PermissionCode::CompanyManage);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->canDo(PermissionCode::CompanyManage);
    }
}
