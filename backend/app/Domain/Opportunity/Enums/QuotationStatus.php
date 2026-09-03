<?php

namespace App\Domain\Opportunity\Enums;

enum QuotationStatus: string
{
    case NotRequired = 'not_required';
    case Preparing = 'preparing';
    case Sent = 'sent';
    case Revised = 'revised';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    /** Statuses where the ball is in the customer's court. */
    public function awaitingCustomer(): bool
    {
        return $this === self::Sent || $this === self::Revised;
    }
}
