<?php

namespace App\Domain\Ai\Enums;

enum ActionRequestStatus: string
{
    case Pending = 'pending';
    case Executed = 'executed';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
