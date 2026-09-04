<?php

namespace App\Policies;

use App\Domain\Document\Models\Document;
use App\Domain\Identity\Enums\PermissionCode;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo(PermissionCode::DocumentView);
    }

    /**
     * Two conditions, both required: permission to see documents at all, and
     * permission to see whatever the document is attached to.
     */
    public function view(User $user, Document $document): bool
    {
        if (! $user->canDo(PermissionCode::DocumentView)) {
            return false;
        }

        if ($document->is_internal && ! $user->canDo(PermissionCode::OpportunityViewInternalNotes)) {
            return false;
        }

        $subject = $document->subject;

        return $subject === null || $user->can('view', $subject);
    }

    public function create(User $user): bool
    {
        return $user->canDo(PermissionCode::DocumentUpload);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->canDo(PermissionCode::DocumentDelete) && $this->view($user, $document);
    }
}
