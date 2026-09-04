<?php

namespace App\Domain\Project\Enums;

enum HandoverItemStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
    case NotApplicable = 'not_applicable';

    /** Both count as "dealt with" for checklist completeness. */
    public function isSettled(): bool
    {
        return $this === self::Done || $this === self::NotApplicable;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In Progress',
            self::Done => 'Done',
            self::NotApplicable => 'Not Applicable',
        };
    }
}
