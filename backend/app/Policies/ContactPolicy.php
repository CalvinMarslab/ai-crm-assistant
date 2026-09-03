<?php

namespace App\Policies;

use App\Domain\Company\Models\Contact;
use App\Domain\Identity\Enums\PermissionCode;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo(PermissionCode::ContactViewAll);
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->canDo(PermissionCode::ContactViewAll);
    }

    public function create(User $user): bool
    {
        return $user->canDo(PermissionCode::ContactManage);
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->canDo(PermissionCode::ContactManage);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->canDo(PermissionCode::ContactManage);
    }
}
