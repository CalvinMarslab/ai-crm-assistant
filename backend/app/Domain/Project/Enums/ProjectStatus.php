<?php

namespace App\Domain\Project\Enums;

/** PRD section 14. */
enum ProjectStatus: string
{
    case PendingHandover = 'pending_handover';
    case Planning = 'planning';
    case InProgress = 'in_progress';
    case WaitingCustomer = 'waiting_customer';
    case InternalReview = 'internal_review';
    case Completed = 'completed';
    case OnHold = 'on_hold';

    public function label(): string
    {
        return match ($this) {
            self::PendingHandover => 'Pending Handover',
            self::Planning => 'Planning',
            self::InProgress => 'In Progress',
            self::WaitingCustomer => 'Waiting for Customer',
            self::InternalReview => 'Internal Review',
            self::Completed => 'Completed',
            self::OnHold => 'On Hold',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Completed;
    }

    /**
     * What a referral agent is shown instead of the internal status
     * (CRM_WORKFLOW.md section 7).
     */
    public function agentFacingStatus(): string
    {
        return $this === self::Completed ? 'Completed' : 'Project In Progress';
    }

    /** Statuses where the ball is not in our court. */
    public function isBlocked(): bool
    {
        return $this === self::WaitingCustomer || $this === self::OnHold;
    }
}
