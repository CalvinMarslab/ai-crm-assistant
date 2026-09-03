<?php

namespace App\Policies;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Enums\PermissionCode;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo(PermissionCode::AuditView);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->canDo(PermissionCode::AuditView);
    }
}
